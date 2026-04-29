<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use PDO;
use Throwable;

/**
 * Phase A: sys_users -> auth.users (via Admin API) + auth.identities + public.user_profiles (+ public.patients)
 * Also maintains public.migration_sys_users_map and a DB checkpoint for resumable runs.
 */
class MigrateSysUsersAuthProfilesCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Phase A: migrate sys_users -> auth + profiles (+patients) + map. Resumable and idempotent.')
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
                'help' => 'For DESC order: start from legacy id < from-id (0 = resume from checkpoint if enabled).',
            ])
            ->addOption('max-rows', [
                'default' => 0,
                'help' => 'Stop after N rows (0 means no limit).',
            ])
            ->addOption('checkpoint-key', [
                'default' => 'sys_users:auth_profiles',
                'help' => 'Checkpoint name used to persist last migrated legacy id.',
            ])
            ->addOption('resume', [
                'boolean' => true,
                'default' => true,
                'help' => 'If true and --from-id=0, resume from checkpoint.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $batch = max(1, (int)$args->getOption('batch'));
        $fromId = max(0, (int)$args->getOption('from-id'));
        $maxRows = max(0, (int)$args->getOption('max-rows'));
        $checkpointKey = (string)$args->getOption('checkpoint-key');
        $resume = (bool)$args->getOption('resume');

        if (!is_file($configPath)) {
            $io->err("Migration config not found: {$configPath}");
            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['legacy'], $cfg['target'])) {
            $io->err("Invalid migration config format in: {$configPath}");
            return static::CODE_ERROR;
        }

        $supabaseApiUrl = rtrim((string)($cfg['target']['api_url'] ?? ''), '/');
        $supabaseServiceRoleKey = (string)($cfg['target']['service_role_key'] ?? '');
        $defaultAuthPassword = (string)($cfg['target']['default_auth_password'] ?? 'TempReset#2026');

        try {
            $legacy = $this->makeMysqlPdo($cfg['legacy']);
            $target = $this->makePgPdo($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connection failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }

        if (!$dryRun && ($supabaseApiUrl === '' || $supabaseServiceRoleKey === '')) {
            $io->err('Missing Supabase Admin API config. Set target.api_url + target.service_role_key.');
            return static::CODE_ERROR;
        }

        if (!$dryRun) {
            $this->ensureCheckpointTable($target);
            $this->ensureMapTable($target);
        }

        $lastId = $fromId > 0 ? $fromId : 4294967295;
        if (!$dryRun && $fromId === 0 && $resume) {
            $cp = $this->getCheckpoint($target, $checkpointKey);
            if ($cp !== null && $cp > 0) {
                $lastId = $cp;
            }
        }
        

        $io->out(sprintf(
            'Phase A start (dry-run=%s, batch=%d, from-id=%d, checkpoint=%s)',
            $dryRun ? 'yes' : 'no',
            $batch,
            $lastId,
            $checkpointKey
        ));

        $processed = 0;
        $migrated = 0;
        $skipped = 0;
        $stopRequested = false;

        while (true) {
            $sql = "
                SELECT
                    su.id, su.uid, su.short_uid, su.name, su.mname, su.lname, su.description, su.email,
                    su.type, su.state, su.zip, su.city, su.street, su.suite, su.phone, su.dob,
                    su.gender, su.bname, su.ein, su.active, su.login_status, su.latitude, su.longitude,
                    su.radius, su.score, su.photo_id, su.stripe_account_confirm, su.stripe_account,
                    su.enable_notifications, su.deleted, su.created, su.modified, su.steps,
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

                    if (!$dryRun && $this->mapAlreadyMigrated($target, (int)$row['id'])) {
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

                    if ($dryRun) {
                        $migrated++;
                        if ($maxRows > 0 && $processed >= $maxRows) {
                            $stopRequested = true;
                            break;
                        }
                        continue;
                    }

                    $legacyMeta = [
                        'legacy_user_id' => (int)$row['id'],
                        'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
                        'legacy_short_uid' => $this->asNullableText($row['short_uid'] ?? null),
                        'legacy_type' => $this->asNullableText($row['type'] ?? null),
                        'legacy_login_status' => $this->asNullableText($row['login_status'] ?? null),
                        'legacy_steps' => $this->asNullableText($row['steps'] ?? null),
                        'legacy_gender' => $this->asNullableText($row['gender'] ?? null),
                        'legacy_ein' => $this->asNullableText($row['ein'] ?? null),
                        'legacy_score' => $row['score'] ?? null,
                        'legacy_photo_id' => $row['photo_id'] ?? null,
                    ];

                    $this->createAuthUserViaApi($supabaseApiUrl, $supabaseServiceRoleKey, $email, $defaultAuthPassword, $legacyMeta);

                    $authRow = $target->prepare("SELECT id FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1");
                    $authRow->execute([':email' => $email]);
                    $auth = $authRow->fetch(PDO::FETCH_ASSOC);
                    if (!$auth) {
                        throw new \RuntimeException('Auth user not found after Admin API create for email: ' . $email);
                    }
                    $authUserId = (string)$auth['id'];

                    // keep auth user metadata healthy/consistent
                    $upd = $target->prepare(
                        "UPDATE auth.users
                         SET raw_app_meta_data = COALESCE(raw_app_meta_data, '{}'::jsonb) || :app_meta::jsonb,
                             instance_id = COALESCE(instance_id, '00000000-0000-0000-0000-000000000000'::uuid),
                             encrypted_password = COALESCE(encrypted_password, crypt(gen_random_uuid()::text, gen_salt('bf'))),
                             email_confirmed_at = COALESCE(email_confirmed_at, now()),
                             updated_at = GREATEST(COALESCE(updated_at, 'epoch'::timestamptz), COALESCE(:updated_at::timestamptz, now())),
                             banned_until = :banned_until::timestamptz
                         WHERE id = :id::uuid"
                    );
                    $upd->execute([
                        ':app_meta' => json_encode(['provider' => 'email', 'providers' => ['email']]),
                        ':updated_at' => $updatedAt,
                        ':banned_until' => $bannedUntil,
                        ':id' => $authUserId,
                    ]);

                    $this->ensureAuthIdentity($target, $authUserId, $email, $createdAt, $updatedAt);

                    // create profile if missing (do NOT update if already exists)
                    $existingProfileStmt = $target->prepare("SELECT id FROM public.user_profiles WHERE user_id = :user_id::uuid LIMIT 1");
                    $existingProfileStmt->execute([':user_id' => $authUserId]);
                    $existingProfile = $existingProfileStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingProfile) {
                        $profileId = (string)$existingProfile['id'];
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
                        $profileId = (string)$profileStmt->fetchColumn();
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
                    $this->setCheckpoint($target, $checkpointKey, $lastId);
                }
            } catch (Throwable $e) {
                if (!$dryRun && $target->inTransaction()) {
                    $target->rollBack();
                }
                $io->err("Phase A failed near legacy id={$lastId}: " . $e->getMessage());
                return static::CODE_ERROR;
            }

            $io->out("Processed={$processed}, migrated={$migrated}, skipped={$skipped}, last_id={$lastId}");
            if ($stopRequested) {
                break;
            }
        }

        $io->success("Phase A done. processed={$processed}, migrated={$migrated}, skipped={$skipped}, last_id={$lastId}");
        return static::CODE_SUCCESS;
    }

    private function ensureCheckpointTable(PDO $target): void
    {
        $target->exec("
            CREATE TABLE IF NOT EXISTS public.migration_checkpoints (
              name text PRIMARY KEY,
              last_legacy_id bigint NOT NULL,
              updated_at timestamptz NOT NULL DEFAULT now()
            )
        ");
    }

    private function getCheckpoint(PDO $target, string $name): ?int
    {
        $stmt = $target->prepare("SELECT last_legacy_id FROM public.migration_checkpoints WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['last_legacy_id'] : null;
    }

    private function setCheckpoint(PDO $target, string $name, int $lastLegacyId): void
    {
        $stmt = $target->prepare(
            "INSERT INTO public.migration_checkpoints (name, last_legacy_id, updated_at)
             VALUES (:name, :id, now())
             ON CONFLICT (name) DO UPDATE SET last_legacy_id = EXCLUDED.last_legacy_id, updated_at = now()"
        );
        $stmt->execute([':name' => $name, ':id' => $lastLegacyId]);
    }

    private function ensureMapTable(PDO $target): void
    {
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

    private function mapAlreadyMigrated(PDO $target, int $legacyUserId): bool
    {
        $stmt = $target->prepare("SELECT 1 FROM public.migration_sys_users_map WHERE legacy_user_id = :id LIMIT 1");
        $stmt->execute([':id' => $legacyUserId]);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureAuthIdentity(PDO $target, string $authUserId, string $email, ?string $createdAt, ?string $updatedAt): void
    {
        $identityData = json_encode([
            'sub' => $authUserId,
            'email' => $email,
            'email_verified' => true,
            'phone_verified' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $target->prepare(
            "INSERT INTO auth.identities (
                id, user_id, identity_data, provider, provider_id, last_sign_in_at, created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :user_id::uuid, :identity_data::jsonb, 'email', :provider_id, NULL,
                COALESCE(:created_at::timestamptz, now()),
                COALESCE(:updated_at::timestamptz, now())
            )
            ON CONFLICT (provider_id, provider) DO UPDATE
            SET identity_data = EXCLUDED.identity_data,
                updated_at = EXCLUDED.updated_at"
        );

        $stmt->execute([
            ':user_id' => $authUserId,
            ':provider_id' => $authUserId,
            ':identity_data' => $identityData,
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
    }

    private function createAuthUserViaApi(string $supabaseApiUrl, string $serviceRoleKey, string $email, string $password, array $legacyMeta): void
    {
        $payload = [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'app_metadata' => [
                'provider' => 'email',
                'providers' => ['email'],
            ],
            'user_metadata' => $legacyMeta,
        ];

        $ch = curl_init($supabaseApiUrl . '/auth/v1/admin/users');
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize curl for Supabase Admin API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $serviceRoleKey,
                'Authorization: Bearer ' . $serviceRoleKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            throw new \RuntimeException('Supabase Admin API create user failed: ' . $curlErr);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return;
        }

        $decoded = json_decode((string)$body, true);
        $msg = is_array($decoded) ? strtolower((string)($decoded['msg'] ?? $decoded['message'] ?? '')) : '';
        if (strpos($msg, 'already') !== false || strpos($msg, 'exists') !== false || strpos($msg, 'registered') !== false) {
            return;
        }

        throw new \RuntimeException('Supabase Admin API create user failed [' . $httpCode . ']: ' . (string)$body);
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
        if (in_array($legacyType, ['patient', 'patient_mint'], true)) {
            return 'patient';
        }
        if (in_array($legacyType, ['injector', 'gfe+ci', 'mint_injector', 'branch_injector', 'clinic', 'school'], true)) {
            return 'provider';
        }
        if ($legacyType === 'examiner') {
            return 'medical_director';
        }
        if (in_array($legacyType, ['master', 'branch_manager', 'mint'], true)) {
            return 'staff';
        }
        return 'none';
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
        $parts = array_filter([trim((string)$name), trim((string)$mname), trim((string)$lname)], function ($v) {
            return $v !== '';
        });
        return empty($parts) ? null : implode(' ', $parts);
    }

    private function joinAddress(?string $street, ?string $suite): ?string
    {
        $parts = array_filter([trim((string)$street), trim((string)$suite)], function ($v) {
            return $v !== '';
        });
        return empty($parts) ? null : implode(', ', $parts);
    }

    private function asNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    private function asNumericText($value): ?string
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

    private function asFloat($value): ?float
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

    private function asDate($value): ?string
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0000-00-00') {
            return null;
        }
        return $v;
    }

    private function toTimestamp($value): ?string
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($v);
        return $ts === false ? null : gmdate('Y-m-d H:i:sP', $ts);
    }
}

