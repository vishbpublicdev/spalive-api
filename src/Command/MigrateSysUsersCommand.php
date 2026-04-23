<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use PDO;
use Throwable;

class MigrateSysUsersCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Migrate legacy sys_users to auth.users + user_profiles (+ patients for patient role).')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Absolute path to migration config file.',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
                'help' => 'Read/transform only. No writes on target DB.',
            ])
            ->addOption('batch', [
                'short' => 'b',
                'default' => 500,
                'help' => 'Batch size (default 500).',
            ])
            ->addOption('from-id', [
                'default' => 0,
                'help' => 'For DESC order: start from legacy id < from-id (0 means latest id).',
            ])
            ->addOption('max-rows', [
                'default' => 0,
                'help' => 'Stop after N rows (0 means no limit).',
            ])
            ->addOption('existing-csv', [
                'default' => LOGS . 'migration_sys_users_existing_profiles.csv',
                'help' => 'CSV file path used when user_profiles.user_id already exists.',
            ])
            ->addOption('dry-run-csv', [
                'default' => LOGS . 'migration_sys_users_dry_run_preview.csv',
                'help' => 'CSV file path used to store transformed preview rows during --dry-run.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $batch = max(1, (int)$args->getOption('batch'));
        $fromId = max(0, (int)$args->getOption('from-id'));
        $maxRows = max(0, (int)$args->getOption('max-rows'));
        $existingCsvPath = (string)$args->getOption('existing-csv');
        $dryRunCsvPath = (string)$args->getOption('dry-run-csv');

        if (!is_file($configPath)) {
            $io->err("Migration config not found: {$configPath}");
            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['legacy'], $cfg['target'])) {
            $io->err("Invalid migration config format in: {$configPath}");
            return static::CODE_ERROR;
        }

        try {
            $legacy = $this->makeMysqlPdo($cfg['legacy']);
            $target = $this->makePgPdo($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connection failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }

        $io->out(sprintf(
            'Starting migration (dry-run=%s, batch=%d, from-id=%d)',
            $dryRun ? 'yes' : 'no',
            $batch,
            $fromId
        ));

        $existingCsvDir = dirname($existingCsvPath);
        if (!is_dir($existingCsvDir)) {
            if (!mkdir($existingCsvDir, 0775, true) && !is_dir($existingCsvDir)) {
                $io->err("Unable to create CSV log directory: {$existingCsvDir}");
                return static::CODE_ERROR;
            }
        }
        $csvHandle = fopen($existingCsvPath, 'ab');
        if ($csvHandle === false) {
            $io->err("Unable to open CSV log file: {$existingCsvPath}");
            return static::CODE_ERROR;
        }
        if (filesize($existingCsvPath) === 0) {
            fputcsv($csvHandle, [
                'logged_at_utc',
                'legacy_user_id',
                'legacy_uid',
                'email',
                'auth_user_id',
                'user_profile_id',
                'reason',
            ]);
        }

        $dryCsvDir = dirname($dryRunCsvPath);
        if (!is_dir($dryCsvDir)) {
            if (!mkdir($dryCsvDir, 0775, true) && !is_dir($dryCsvDir)) {
                $io->err("Unable to create dry-run CSV directory: {$dryCsvDir}");
                fclose($csvHandle);
                return static::CODE_ERROR;
            }
        }
        $dryCsvHandle = fopen($dryRunCsvPath, 'ab');
        if ($dryCsvHandle === false) {
            $io->err("Unable to open dry-run CSV file: {$dryRunCsvPath}");
            fclose($csvHandle);
            return static::CODE_ERROR;
        }
        if (filesize($dryRunCsvPath) === 0) {
            fputcsv($dryCsvHandle, [
                'logged_at_utc',
                'legacy_user_id',
                'legacy_uid',
                'legacy_short_uid',
                'email',
                'mapped_app_role',
                'mapped_onboarding_status',
                'is_active',
                'push_notifications_enabled',
                'state',
                'city',
                'zip_code',
                'full_name',
                'practice_name',
                'practice_address',
                'phone',
                'dob',
                'stripe_account_id',
                'stripe_onboarding_complete',
                'created_at',
                'updated_at',
                'would_insert_patient',
            ]);
        }

        if (!$dryRun) {
            $target->exec("
                CREATE TABLE IF NOT EXISTS public.migration_sys_users_map (
                  legacy_user_id bigint PRIMARY KEY,
                  legacy_uid text,
                  legacy_email text,
                  auth_user_id uuid UNIQUE,
                  user_profile_id uuid,
                  mapped_app_role text,
                  migrated_at timestamptz NOT NULL DEFAULT now()
                )
            ");
        }

        $processed = 0;
        $migrated = 0;
        $skipped = 0;
        $lastId = $fromId > 0 ? $fromId : 4294967295;
        $stopRequested = false;

        while (true) {
            $sql = "
                SELECT
                    su.id, su.uid, su.short_uid, su.name, su.mname, su.lname, su.description, su.email, su.password,
                    su.type, su.state, su.zip, su.city, su.street, su.suite, su.phone, su.dob, su.gender, su.bname, su.ein,
                    su.active, su.login_status, su.latitude, su.longitude, su.radius, su.score, su.photo_id,
                    su.stripe_account_confirm, su.stripe_account, su.i_nine_id, su.ten_nintynine_id, su.amount, su.payment,
                    su.payment_intent, su.receipt_url, su.tracers, su.tracers_sxo, su.is_test, su.enable_notifications, su.deleted,
                    su.created, su.createdby, su.modified, su.modifiedby, su.show_in_map, su.show_most_review, su.last_status_change,
                    su.custom_pay, su.md_id, su.steps, su.spa_work, su.sales_rep_status, su.treatment_type, su.provider_url,
                    su.speak_spanish, su.branch_manager, su.filler_check,
                    COALESCE(cs.abv, cs.name, CAST(su.state AS CHAR)) AS state_text
                FROM sys_users su
                LEFT JOIN cat_states cs ON cs.id = su.state
                WHERE COALESCE(su.deleted, 0) = 0
                  AND su.id < :last_id
                  AND su.email IS NOT NULL
                  AND TRIM(su.email) <> ''
                  AND su.email LIKE '%@%'
                ORDER BY su.id DESC
                LIMIT :batch
            ";

            $stmt = $legacy->prepare($sql);
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':batch', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            if (!$dryRun) {
                $target->beginTransaction();
            }

            try {
                foreach ($rows as $row) {
                    $processed++;
                    $lastId = (int)$row['id'];

                    $email = strtolower(trim((string)$row['email']));
                    if ($email === '' || strpos($email, '@') === false) {
                        $skipped++;
                        continue;
                    }

                    $fullName = $this->buildFullName($row['name'] ?? null, $row['mname'] ?? null, $row['lname'] ?? null);
                    $appRole = $this->mapRole((string)$row['type']);
                    $onboarding = $this->mapOnboardingStatus((string)($row['steps'] ?? ''));
                    $isProviderLike = in_array($appRole, ['provider', 'medical_director', 'staff'], true);
                    $isActive = ((int)$row['active'] === 1);
                    $bannedUntil = $isActive ? null : gmdate('Y-m-d H:i:sP');
                    $pushEnabled = ((int)$row['enable_notifications'] === 1);
                    $practiceAddress = $this->joinAddress($row['street'] ?? null, $row['suite'] ?? null);
                    $createdAt = $this->toTimestamp($row['created'] ?? null);
                    $updatedAt = $this->toTimestamp($row['modified'] ?? null) ?: $createdAt;

                    $legacyMetaJson = json_encode([
                        'legacy_user_id' => (int)$row['id'],
                        'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
                        'legacy_short_uid' => $this->asNullableText($row['short_uid'] ?? null),
                        'legacy_type' => $this->asNullableText($row['type'] ?? null),
                        'legacy_login_status' => $this->asNullableText($row['login_status'] ?? null),
                        'legacy_steps' => $this->asNullableText($row['steps'] ?? null),
                        'legacy_gender' => $this->asNullableText($row['gender'] ?? null),
                        'legacy_ein' => $this->asNullableText($row['ein'] ?? null),
                        'legacy_score' => $row['score'],
                        'legacy_photo_id' => $row['photo_id'],
                        'legacy_i_nine_id' => $row['i_nine_id'],
                        'legacy_ten_nintynine_id' => $row['ten_nintynine_id'],
                        'legacy_amount' => $row['amount'],
                        'legacy_payment' => $this->asNullableText($row['payment'] ?? null),
                        'legacy_payment_intent' => $this->asNullableText($row['payment_intent'] ?? null),
                        'legacy_receipt_url' => $this->asNullableText($row['receipt_url'] ?? null),
                        'legacy_tracers' => $this->asNullableText($row['tracers'] ?? null),
                        'legacy_tracers_sxo' => $this->asNullableText($row['tracers_sxo'] ?? null),
                        'legacy_is_test' => $row['is_test'],
                        'legacy_createdby' => $row['createdby'],
                        'legacy_modifiedby' => $row['modifiedby'],
                        'legacy_show_in_map' => $row['show_in_map'],
                        'legacy_show_most_review' => $row['show_most_review'],
                        'legacy_last_status_change' => $row['last_status_change'],
                        'legacy_custom_pay' => $row['custom_pay'],
                        'legacy_md_id' => $row['md_id'],
                        'legacy_spa_work' => $row['spa_work'],
                        'legacy_sales_rep_status' => $row['sales_rep_status'],
                        'legacy_treatment_type' => $row['treatment_type'],
                        'legacy_provider_url' => $row['provider_url'],
                        'legacy_speak_spanish' => $row['speak_spanish'],
                        'legacy_branch_manager' => $row['branch_manager'],
                        'legacy_filler_check' => $row['filler_check'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($dryRun) {
                        fputcsv($dryCsvHandle, [
                            gmdate('c'),
                            (int)$row['id'],
                            $this->asNullableText($row['uid'] ?? null),
                            $this->asNullableText($row['short_uid'] ?? null),
                            $email,
                            $appRole,
                            $onboarding,
                            $isActive ? 'true' : 'false',
                            $pushEnabled ? 'true' : 'false',
                            $this->asNullableText($row['state_text'] ?? null),
                            $this->asNullableText($row['city'] ?? null),
                            $this->asNumericText($row['zip'] ?? null),
                            $fullName,
                            $isProviderLike ? $this->asNullableText($row['bname'] ?? null) : null,
                            $practiceAddress,
                            $this->asNullableText($row['phone'] ?? null),
                            $this->asDate($row['dob'] ?? null),
                            $isProviderLike ? $this->asNullableText($row['stripe_account'] ?? null) : null,
                            $isProviderLike && ((int)$row['stripe_account_confirm'] === 1) ? 'true' : 'false',
                            $createdAt,
                            $updatedAt,
                            $appRole === 'patient' ? 'yes' : 'no',
                        ]);
                        $migrated++;
                        if ($maxRows > 0 && $processed >= $maxRows) {
                            $stopRequested = true;
                            break;
                        }
                        continue;
                    }

                    $authRow = $target->prepare("SELECT id FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1");
                    $authRow->execute([':email' => $email]);
                    $auth = $authRow->fetch(PDO::FETCH_ASSOC);

                    if ($auth) {
                        $authUserId = $auth['id'];
                        $upd = $target->prepare(
                            "UPDATE auth.users
                             SET raw_user_meta_data = COALESCE(raw_user_meta_data, '{}'::jsonb) || :meta::jsonb,
                                 updated_at = GREATEST(COALESCE(updated_at, 'epoch'::timestamptz), COALESCE(:updated_at::timestamptz, now())),
                                 banned_until = :banned_until::timestamptz
                             WHERE id = :id::uuid"
                        );
                        $upd->execute([
                            ':meta' => $legacyMetaJson,
                            ':updated_at' => $updatedAt,
                            ':banned_until' => $bannedUntil,
                            ':id' => $authUserId,
                        ]);
                    } else {
                        $ins = $target->prepare(
                            "INSERT INTO auth.users (
                                id, aud, role, email, encrypted_password, email_confirmed_at,
                                raw_app_meta_data, raw_user_meta_data, created_at, updated_at, banned_until
                            ) VALUES (
                                gen_random_uuid(), 'authenticated', 'authenticated', :email, NULL, now(),
                                :app_meta::jsonb, :meta::jsonb,
                                COALESCE(:created_at::timestamptz, now()),
                                COALESCE(:updated_at::timestamptz, now()),
                                :banned_until::timestamptz
                            ) RETURNING id"
                        );
                        $ins->execute([
                            ':email' => $email,
                            ':app_meta' => json_encode(['provider' => 'email', 'providers' => ['email']]),
                            ':meta' => $legacyMetaJson,
                            ':created_at' => $createdAt,
                            ':updated_at' => $updatedAt,
                            ':banned_until' => $bannedUntil,
                        ]);
                        $authUserId = $ins->fetchColumn();
                    }

                    $existingProfileStmt = $target->prepare(
                        "SELECT id FROM public.user_profiles WHERE user_id = :user_id::uuid LIMIT 1"
                    );
                    $existingProfileStmt->execute([':user_id' => $authUserId]);
                    $existingProfile = $existingProfileStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingProfile) {
                        $profileId = $existingProfile['id'];
                        fputcsv($csvHandle, [
                            gmdate('c'),
                            (int)$row['id'],
                            $this->asNullableText($row['uid'] ?? null),
                            $email,
                            $authUserId,
                            $profileId,
                            'user_profiles.user_id already exists; skipped update',
                        ]);
                    } else {
                        $profileStmt = $target->prepare(
                            "INSERT INTO public.user_profiles (
                                user_id, email, full_name, phone, bio, app_role, onboarding_status, account_flag,
                                is_active, push_notifications_enabled, state, city, zip_code, practice_name, practice_address,
                                latitude, longitude, mobile_service_radius_miles, stripe_account_id, stripe_onboarding_complete,
                                created_at, updated_at
                            ) VALUES (
                                :user_id::uuid, :email, :full_name, :phone, :bio, :app_role, :onboarding_status, :account_flag,
                            :is_active::boolean, :push_enabled::boolean, :state, :city, :zip_code, :practice_name, :practice_address,
                            :latitude, :longitude, :radius, :stripe_account_id, :stripe_onboarding_complete::boolean,
                                COALESCE(:created_at::timestamptz, now()),
                                COALESCE(:updated_at::timestamptz, now())
                            )
                            RETURNING id"
                        );
                        $profileStmt->execute([
                            ':user_id' => $authUserId,
                            ':email' => $email,
                            ':full_name' => $fullName,
                            ':phone' => $this->asNullableText($row['phone'] ?? null),
                            ':bio' => $this->asNullableText($row['description'] ?? null),
                            ':app_role' => $appRole,
                            ':onboarding_status' => $onboarding,
                            ':account_flag' => $this->asNullableText($row['login_status'] ?? null),
                            ':is_active' => $this->asPgBoolean($isActive),
                            ':push_enabled' => $this->asPgBoolean($pushEnabled),
                            ':state' => $this->asNullableText($row['state_text'] ?? null),
                            ':city' => $this->asNullableText($row['city'] ?? null),
                            ':zip_code' => $this->asNumericText($row['zip'] ?? null),
                            ':practice_name' => $isProviderLike ? $this->asNullableText($row['bname'] ?? null) : null,
                            ':practice_address' => $practiceAddress,
                            ':latitude' => $this->asFloat($row['latitude'] ?? null),
                            ':longitude' => $this->asFloat($row['longitude'] ?? null),
                            ':radius' => $isProviderLike ? $this->asFloat($row['radius'] ?? null) : null,
                            ':stripe_account_id' => $isProviderLike ? $this->asNullableText($row['stripe_account'] ?? null) : null,
                            ':stripe_onboarding_complete' => $this->asPgBoolean($isProviderLike ? ((int)$row['stripe_account_confirm'] === 1) : false),
                            ':created_at' => $createdAt,
                            ':updated_at' => $updatedAt,
                        ]);
                        $profileId = $profileStmt->fetchColumn();
                    }

                    if ($appRole === 'patient') {
                        $patientStmt = $target->prepare(
                            "INSERT INTO public.patients (
                                patient_user_id, invite_email, temporary_name, temporary_phone, date_of_birth, phone, address,
                                city, state, zip_code, latitude, longitude, registration_status, created_at, updated_at
                            )
                            SELECT
                                :patient_user_id::uuid, :invite_email, :temporary_name, :temporary_phone, :dob::date, :phone, :address,
                                :city, :state, :zip_code, :latitude, :longitude, 'registered',
                                COALESCE(:created_at::timestamptz, now()),
                                COALESCE(:updated_at::timestamptz, now())
                            WHERE NOT EXISTS (
                                SELECT 1 FROM public.patients p WHERE p.patient_user_id = :patient_user_id::uuid
                            )"
                        );
                        $patientStmt->execute([
                            ':patient_user_id' => $authUserId,
                            ':invite_email' => $email,
                            ':temporary_name' => $fullName,
                            ':temporary_phone' => $this->asNullableText($row['phone'] ?? null),
                            ':dob' => $this->asDate($row['dob'] ?? null),
                            ':phone' => $this->asNullableText($row['phone'] ?? null),
                            ':address' => $practiceAddress,
                            ':city' => $this->asNullableText($row['city'] ?? null),
                            ':state' => $this->asNullableText($row['state_text'] ?? null),
                            ':zip_code' => $this->asNumericText($row['zip'] ?? null),
                            ':latitude' => $this->asFloat($row['latitude'] ?? null),
                            ':longitude' => $this->asFloat($row['longitude'] ?? null),
                            ':created_at' => $createdAt,
                            ':updated_at' => $updatedAt,
                        ]);
                    }

                    $mapStmt = $target->prepare(
                        "INSERT INTO public.migration_sys_users_map (
                            legacy_user_id, legacy_uid, legacy_email, auth_user_id, user_profile_id, mapped_app_role, migrated_at
                        ) VALUES (
                            :legacy_user_id, :legacy_uid, :legacy_email, :auth_user_id::uuid, :user_profile_id::uuid, :mapped_app_role, now()
                        )
                        ON CONFLICT (legacy_user_id) DO UPDATE SET
                            legacy_uid = EXCLUDED.legacy_uid,
                            legacy_email = EXCLUDED.legacy_email,
                            auth_user_id = EXCLUDED.auth_user_id,
                            user_profile_id = EXCLUDED.user_profile_id,
                            mapped_app_role = EXCLUDED.mapped_app_role,
                            migrated_at = EXCLUDED.migrated_at"
                    );
                    $mapStmt->execute([
                        ':legacy_user_id' => (int)$row['id'],
                        ':legacy_uid' => $this->asNullableText($row['uid'] ?? null),
                        ':legacy_email' => $email,
                        ':auth_user_id' => $authUserId,
                        ':user_profile_id' => $profileId,
                        ':mapped_app_role' => $appRole,
                    ]);

                    $migrated++;
                    if ($maxRows > 0 && $processed >= $maxRows) {
                        $stopRequested = true;
                        break;
                    }
                }

                if (!$dryRun) {
                    $target->commit();
                }
            } catch (Throwable $e) {
                if (!$dryRun && $target->inTransaction()) {
                    $target->rollBack();
                }
                $io->err("Migration failed near legacy id={$lastId}: " . $e->getMessage());
                fclose($csvHandle);
                fclose($dryCsvHandle);
                return static::CODE_ERROR;
            }

            $io->out("Processed={$processed}, migrated={$migrated}, skipped={$skipped}, last_id={$lastId}");
            if ($stopRequested) {
                break;
            }
        }

        $io->success("Done. processed={$processed}, migrated={$migrated}, skipped={$skipped}, last_id={$lastId}");
        fclose($csvHandle);
        fclose($dryCsvHandle);
        return static::CODE_SUCCESS;
    }

    private function makeMysqlPdo(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int)($cfg['port'] ?? 3306),
            $cfg['database']
        );

        return new PDO($dsn, (string)$cfg['username'], (string)$cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function makePgPdo(array $cfg): PDO
    {
        $dsn = (string)($cfg['dsn'] ?? '');
        if ($dsn === '') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d',
                $cfg['host'],
                (int)($cfg['port'] ?? 5432),
                $cfg['database'],
                (string)($cfg['sslmode'] ?? 'require'),
                (int)($cfg['connect_timeout'] ?? 15)
            );
        }

        return new PDO($dsn, (string)$cfg['username'], (string)$cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function mapRole(string $legacyType): string
    {
        return match ($legacyType) {
            'patient', 'patient_mint' => 'patient',
            'injector', 'gfe+ci', 'mint_injector', 'branch_injector', 'clinic', 'school' => 'provider',
            'examiner' => 'medical_director',
            'master', 'branch_manager', 'mint' => 'staff',
            default => 'none',
        };
    }

    private function mapOnboardingStatus(string $steps): string
    {
        $s = trim($steps);
        if ($s === '') {
            return 'not_started';
        }
        if (in_array($s, ['REGISTER', 'CODEVERIFICATION', 'PAYMENTMETHOD', 'HOWITWORKS', 'SHORTFORM', 'LONGFORM', 'LONGFORMOFFCODE', 'LONGFORMSKIPGFE'], true)) {
            return 'info_submitted';
        }
        if (in_array($s, ['SELECTREFERRED', 'SELECTBASICCOURSE', 'SELECTADVANCEDCOURSE', 'SELECTFILLERS', 'TREATMENTINFO'], true)) {
            return 'path_selected';
        }
        if (in_array($s, ['BASICCOURSE', 'ADVANCEDCOURSE', 'CERTIFICATIONS', 'CPR', 'TRACERS', 'IVTHERAPYVIDEOWATCHED'], true)) {
            return 'course_enrolled';
        }
        if (in_array($s, ['WAITINGFORAPPROVAL', 'WAITINGIVAPPROVAL', 'WAITINGFILLERSAPPROVAL', 'WAITINGSCHOOLAPPROVAL', 'LICENCEEXAMINER', 'LICENCEOT'], true)) {
            return 'pending_verification';
        }
        if (in_array($s, ['HOME', 'STARTPROVIDINGTREATMENTS', 'APPIVAPPROVED', 'FILLERSAPPROVED', 'PAIDGFE', 'GFEFREE'], true)) {
            return 'completed';
        }
        return 'exploring';
    }

    private function buildFullName(?string $name, ?string $mname, ?string $lname): ?string
    {
        $parts = array_filter([trim((string)$name), trim((string)$mname), trim((string)$lname)], fn($v) => $v !== '');
        return empty($parts) ? null : implode(' ', $parts);
    }

    private function joinAddress(?string $street, ?string $suite): ?string
    {
        $parts = array_filter([trim((string)$street), trim((string)$suite)], fn($v) => $v !== '');
        return empty($parts) ? null : implode(', ', $parts);
    }

    private function asNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    private function asNumericText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        if ($v === '' || $v === '0') {
            return null;
        }
        return $v;
    }

    private function asFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function asPgBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function asDate(mixed $value): ?string
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0000-00-00') {
            return null;
        }
        return $v;
    }

    private function toTimestamp(mixed $value): ?string
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($v);
        return $ts === false ? null : gmdate('Y-m-d H:i:sP', $ts);
    }
}

