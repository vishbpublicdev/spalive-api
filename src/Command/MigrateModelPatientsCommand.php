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
 * Legacy model patients -> public.model_patients (+ model_patient_session_enrollments).
 *
 * Sources:
 *   - data_model_patient (application / assignment rows)
 *   - cat_trainings (requested_training_id / registered_training_id)
 *
 * Targets:
 *   - public.model_patients
 *   - public.model_patient_session_enrollments (when course_sessions.legacy_training_id resolves)
 *
 * Requires: sql/migration_model_patients_map.sql on target.
 * Recommended: course_sessions.legacy_training_id populated (Port2Pay / training migration).
 *
 * Run: bin/cake migrate_model_patients --config config/migration_sys_users_LIVE.php [--dry-run]
 */
class MigrateModelPatientsCommand extends Command
{
    private const CHECKPOINT = 'data_model_patient:model_patients';

    private const DEFAULT_LEGACY_TIMEZONE = 'America/Chicago';

    private string $legacyTimezone = self::DEFAULT_LEGACY_TIMEZONE;

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Migrate legacy data_model_patient to public.model_patients (+ session enrollments).')
            ->addOption('config', ['default' => CONFIG . 'migration_sys_users.php'])
            ->addOption('dry-run', ['boolean' => true, 'default' => false])
            ->addOption('batch', ['short' => 'b', 'default' => 200])
            ->addOption('from-id', ['default' => 0])
            ->addOption('max-rows', ['default' => 0])
            ->addOption('no-resume', ['boolean' => true, 'default' => false])
            ->addOption('force', [
                'boolean' => true,
                'default' => false,
                'help' => 'Re-migrate rows already present in migration_model_patients_map.',
            ])
            ->addOption('include-deleted', [
                'boolean' => true,
                'default' => false,
                'help' => 'Include legacy rows with deleted=1.',
            ])
            ->addOption('promote-profiles', [
                'boolean' => true,
                'default' => false,
                'help' => 'Set user_profiles.app_role=model_patient when auth user matches email.',
            ])
            ->addOption('duplicates-csv', [
                'default' => '',
                'help' => 'CSV log for skipped duplicate session enrollments (default: logs/migration_model_patients_duplicate_enrollments_{dev|LIVE}.csv).',
            ])
            ->addOption('legacy-timezone', [
                'default' => self::DEFAULT_LEGACY_TIMEZONE,
                'help' => 'IANA timezone for legacy cat_trainings.scheduled (default: America/Chicago).',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $batch = max(1, (int)$args->getOption('batch'));
        $fromId = max(0, (int)$args->getOption('from-id'));
        $maxRows = max(0, (int)$args->getOption('max-rows'));
        $resume = !(bool)$args->getOption('no-resume');
        $force = (bool)$args->getOption('force');
        $includeDeleted = (bool)$args->getOption('include-deleted');
        $promoteProfiles = (bool)$args->getOption('promote-profiles');
        $this->legacyTimezone = trim((string)$args->getOption('legacy-timezone')) ?: self::DEFAULT_LEGACY_TIMEZONE;
        $envLabel = $this->resolveEnvLabel($configPath);
        $duplicatesCsv = trim((string)$args->getOption('duplicates-csv'));
        if ($duplicatesCsv === '') {
            $duplicatesCsv = LOGS . 'migration_model_patients_duplicate_enrollments_' . $envLabel . '.csv';
        }

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;

        try {
            $legacy = $this->pdoMysql($cfg['legacy']);
            $target = $this->pdoPg($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connect failed: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        if (!$dryRun) {
            $this->ensureMapTable($target);
            $this->ensureCheckpointTable($target);
        }

        $lastId = $fromId;
        if ($resume && $fromId === 0 && !$dryRun) {
            $cp = $this->checkpointGet($target, self::CHECKPOINT);
            if ($cp !== null && $cp > 0) {
                $lastId = $cp;
                $io->out("Resuming from legacy data_model_patient.id > {$lastId}");
            }
        }

        $io->out(sprintf(
            'Model patients migration env=%s dry-run=%s batch=%d from-id>%d promote-profiles=%s legacy-tz=%s duplicates-csv=%s',
            $envLabel,
            $dryRun ? 'yes' : 'no',
            $batch,
            $lastId,
            $promoteProfiles ? 'yes' : 'no',
            $this->legacyTimezone,
            $duplicatesCsv
        ));

        $duplicateCsvHandle = $this->openDuplicatesCsv($duplicatesCsv);
        $seenSessionPatient = [];

        $examined = 0;
        $migrated = 0;
        $skipped = 0;
        $duplicateSkipped = 0;
        $noSession = 0;
        $noAuthUser = 0;
        $stop = false;

        while (!$stop) {
            $rows = $this->fetchBatch($legacy, $lastId, $batch, $includeDeleted);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $examined++;
                $legacyId = (int)$row['id'];
                $lastId = $legacyId;

                if ($maxRows > 0 && $examined > $maxRows) {
                    $stop = true;
                    break;
                }

                $email = strtolower(trim((string)($row['email'] ?? '')));
                if ($email === '') {
                    $io->warning("skip id={$legacyId}: missing email");
                    $skipped++;
                    continue;
                }

                if (!$force && !$dryRun && $this->mapExists($target, $legacyId)) {
                    $skipped++;
                    continue;
                }

                $trainingId = $this->resolveTrainingId($row);
                $training = $trainingId > 0 ? $this->fetchTraining($legacy, $trainingId) : null;
                $session = $trainingId > 0 ? $this->resolveCourseSession($target, $trainingId) : null;

                if ($trainingId > 0 && $session === null) {
                    $noSession++;
                }

                $patientUserId = $this->resolveAuthUserId($target, $email);
                if ($patientUserId === null) {
                    $noAuthUser++;
                }

                $fullName = $this->buildFullName($row);
                $phone = $this->requiredPhone($row['phone'] ?? null);
                $status = $this->mapStatus($row, $training);
                $treatmentType = $this->mapTreatmentType($training, $session);
                $sessionDate = $this->resolveSessionDate($training, $session);
                $courseTitle = $this->resolveCourseTitle($training, $session);
                $legacyStatus = (string)($row['status'] ?? '');
                $notes = $this->buildNotes($legacyId, $row, $trainingId, $legacyStatus);

                $duplicateSkip = $this->resolveDuplicateEnrollmentSkip(
                    $target,
                    $session,
                    $patientUserId,
                    $status,
                    $seenSessionPatient
                );
                if ($duplicateSkip !== null) {
                    $this->logDuplicateSkip($duplicateCsvHandle, [
                        'legacy_data_model_patient_id' => $legacyId,
                        'email' => $email,
                        'full_name' => $fullName,
                        'legacy_status' => $legacyStatus,
                        'requested_training_id' => (int)($row['requested_training_id'] ?? 0),
                        'registered_training_id' => (int)($row['registered_training_id'] ?? 0),
                        'legacy_training_id' => $trainingId,
                        'patient_user_id' => $patientUserId ?? '',
                        'course_session_id' => $session['course_session_id'] ?? '',
                        'course_id' => $session['course_id'] ?? '',
                        'existing_enrollment_id' => $duplicateSkip['enrollment_id'] ?? '',
                        'existing_model_patient_id' => $duplicateSkip['model_patient_id'] ?? '',
                        'existing_legacy_data_model_patient_id' => $duplicateSkip['legacy_data_model_patient_id'] ?? '',
                        'skip_reason' => $duplicateSkip['skip_reason'] ?? 'duplicate_session_patient',
                        'dry_run' => $dryRun ? 'yes' : 'no',
                    ]);
                    $io->warning(sprintf(
                        'skip id=%d email=%s status=%s: duplicate session+course+user+status (existing legacy id=%s)',
                        $legacyId,
                        $email,
                        $status,
                        $duplicateSkip['legacy_data_model_patient_id'] ?? 'unknown'
                    ));
                    $duplicateSkipped++;
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $io->out(sprintf(
                        '  [dry-run] id=%d email=%s status=%s training=%d session=%s auth=%s',
                        $legacyId,
                        $email,
                        $status,
                        $trainingId,
                        $session['course_session_id'] ?? 'none',
                        $patientUserId ?? 'none'
                    ));
                    if ($session !== null && $patientUserId !== null) {
                        $seenSessionPatient[$this->enrollmentDedupeKey(
                            (string)$session['course_session_id'],
                            (string)$session['course_id'],
                            $patientUserId,
                            $status
                        )] = $legacyId;
                    }
                    $migrated++;
                    continue;
                }

                try {
                    if (!$target->inTransaction()) {
                        $target->beginTransaction();
                    }

                    if ($force) {
                        $this->deleteExistingMapRow($target, $legacyId);
                    }

                    $modelPatientId = $this->insertModelPatient($target, [
                        'patient_user_id' => $patientUserId,
                        'full_name' => $fullName,
                        'email' => $email,
                        'phone' => $phone,
                        'date_of_birth' => null,
                        'course_title' => $courseTitle,
                        'treatment_type' => $treatmentType,
                        'session_date' => $sessionDate,
                        'status' => $status,
                        'course_id' => $session['course_id'] ?? null,
                        'course_session_id' => $session['course_session_id'] ?? null,
                        'photo_consent' => false,
                        'medical_history' => null,
                        'allergies' => null,
                        'current_medications' => null,
                        'consent_signed' => strtoupper((string)($row['understand'] ?? '')) === 'YES',
                        'consent_signed_date' => strtoupper((string)($row['understand'] ?? '')) === 'YES'
                            ? $this->ts($row['created'] ?? null)
                            : null,
                        'waiver_signed' => strtoupper((string)($row['gfe'] ?? '')) === 'YES',
                        'confirmed_by_admin' => $legacyStatus === 'assigned',
                        'confirmed_by_admin_date' => $legacyStatus === 'assigned'
                            ? $this->ts($row['created'] ?? null)
                            : null,
                        'notes' => $notes,
                        'created_at' => $this->ts($row['created'] ?? null),
                        'updated_at' => $this->ts($row['created'] ?? null),
                    ]);

                    $enrollmentId = null;
                    if ($session !== null && $modelPatientId !== '') {
                        $enrollmentId = $this->insertEnrollment($target, [
                            'model_patient_id' => $modelPatientId,
                            'course_session_id' => $session['course_session_id'],
                            'course_id' => $session['course_id'],
                            'patient_user_id' => $patientUserId,
                            'treatment_type' => $treatmentType,
                            'status' => $status,
                        ]);
                    }

                    $this->insertMap($target, $legacyId, $modelPatientId, $enrollmentId, $trainingId, $session, $patientUserId);

                    if ($promoteProfiles && $patientUserId !== null) {
                        $this->promoteUserProfile($target, $patientUserId);
                    }

                    $target->commit();
                    if ($session !== null && $patientUserId !== null && $enrollmentId !== null) {
                        $seenSessionPatient[$this->enrollmentDedupeKey(
                            (string)$session['course_session_id'],
                            (string)$session['course_id'],
                            $patientUserId,
                            $status
                        )] = $legacyId;
                    }
                    $migrated++;
                } catch (Throwable $e) {
                    if ($target->inTransaction()) {
                        $target->rollBack();
                    }
                    if ($this->isDuplicateSessionEnrollmentError($e)) {
                        $existing = ($session !== null && $patientUserId !== null)
                            ? $this->findExistingSessionEnrollment(
                                $target,
                                (string)$session['course_session_id'],
                                $patientUserId,
                                null,
                                null
                            )
                            : null;
                        $this->logDuplicateSkip($duplicateCsvHandle, [
                            'legacy_data_model_patient_id' => $legacyId,
                            'email' => $email,
                            'full_name' => $fullName,
                            'legacy_status' => $legacyStatus,
                            'requested_training_id' => (int)($row['requested_training_id'] ?? 0),
                            'registered_training_id' => (int)($row['registered_training_id'] ?? 0),
                            'legacy_training_id' => $trainingId,
                            'patient_user_id' => $patientUserId ?? '',
                            'course_session_id' => $session['course_session_id'] ?? '',
                            'course_id' => $session['course_id'] ?? '',
                            'existing_enrollment_id' => $existing['enrollment_id'] ?? '',
                            'existing_model_patient_id' => $existing['model_patient_id'] ?? '',
                            'existing_legacy_data_model_patient_id' => $existing['legacy_data_model_patient_id'] ?? '',
                            'skip_reason' => 'duplicate_session_patient_db_conflict',
                            'dry_run' => 'no',
                        ]);
                        $io->warning(sprintf(
                            'skip id=%d email=%s: duplicate session enrollment (db conflict)',
                            $legacyId,
                            $email
                        ));
                        $duplicateSkipped++;
                        $skipped++;
                        continue;
                    }
                    $io->err("Failed legacy id={$legacyId}: " . $e->getMessage());

                    return static::CODE_ERROR;
                }
            }

            if (!$dryRun) {
                $this->checkpointSet($target, self::CHECKPOINT, $lastId);
            }
        }

        if (is_resource($duplicateCsvHandle)) {
            fclose($duplicateCsvHandle);
        }

        $io->success(sprintf(
            'Done. examined=%d migrated=%d skipped=%d duplicate_skipped=%d no_session=%d no_auth_user=%d checkpoint_id=%d duplicates_csv=%s',
            $examined,
            $migrated,
            $skipped,
            $duplicateSkipped,
            $noSession,
            $noAuthUser,
            $lastId,
            $duplicatesCsv
        ));

        return static::CODE_SUCCESS;
    }

    private function fetchBatch(PDO $legacy, int $afterId, int $limit, bool $includeDeleted): array
    {
        $deletedClause = $includeDeleted ? '' : 'AND mp.deleted = 0';
        $sql = "
            SELECT mp.*
            FROM data_model_patient mp
            WHERE mp.id > :after_id
              {$deletedClause}
            ORDER BY mp.id ASC
            LIMIT {$limit}
        ";
        $st = $legacy->prepare($sql);
        $st->execute([':after_id' => $afterId]);

        return $st->fetchAll();
    }

    private function fetchTraining(PDO $legacy, int $trainingId): ?array
    {
        $st = $legacy->prepare(
            'SELECT id, title, scheduled, level, neurotoxins, fillers, deleted
             FROM cat_trainings
             WHERE id = :id
             LIMIT 1'
        );
        $st->execute([':id' => $trainingId]);
        $row = $st->fetch();

        return $row ?: null;
    }

    private function resolveTrainingId(array $row): int
    {
        $registered = (int)($row['registered_training_id'] ?? 0);
        if ($registered > 0) {
            return $registered;
        }

        return (int)($row['requested_training_id'] ?? 0);
    }

    private function resolveCourseSession(PDO $target, int $legacyTrainingId): ?array
    {
        $st = $target->prepare(
            'SELECT cs.id AS course_session_id,
                    cs.course_id,
                    cs.session_date,
                    cs.start_time,
                    c.title AS course_title,
                    c.category AS course_category
             FROM public.course_sessions cs
             LEFT JOIN public.courses c ON c.id = cs.course_id
             WHERE cs.legacy_training_id = :legacy_training_id
             ORDER BY cs.is_active DESC, cs.session_date DESC NULLS LAST, cs.created_at DESC
             LIMIT 1'
        );
        $st->execute([':legacy_training_id' => $legacyTrainingId]);
        $row = $st->fetch();

        return $row ?: null;
    }

    private function resolveAuthUserId(PDO $target, string $email): ?string
    {
        $st = $target->prepare(
            'SELECT id::text FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1'
        );
        $st->execute([':email' => $email]);
        $id = $st->fetchColumn();

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function buildFullName(array $row): string
    {
        $parts = array_filter([
            trim((string)($row['name'] ?? '')),
            trim((string)($row['mname'] ?? '')),
            trim((string)($row['lname'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        $full = trim(implode(' ', $parts));

        return $full !== '' ? $full : trim((string)($row['email'] ?? 'Model Patient'));
    }

    private function requiredPhone($value): string
    {
        $phone = trim((string)($value ?? ''));

        return $phone !== '' ? $phone : 'TBD';
    }

    private function mapStatus(array $row, ?array $training): string
    {
        $legacyStatus = strtolower(trim((string)($row['status'] ?? '')));
        if ($legacyStatus === 'cancel') {
            return 'cancelled';
        }
        if ($legacyStatus !== 'assigned') {
            return 'pending';
        }

        $scheduledUtc = $this->legacyLocalToUtc($this->ts($training['scheduled'] ?? null));
        if ($scheduledUtc !== null) {
            $ts = strtotime($scheduledUtc);
            if ($ts !== false && $ts < time()) {
                return 'completed';
            }
        }

        return 'confirmed';
    }

    private function mapTreatmentType(?array $training, ?array $session): string
    {
        if ($session !== null && !empty($session['course_category'])) {
            return (string)$session['course_category'];
        }

        if ($training === null) {
            return 'pending_selection';
        }

        $level = strtoupper(trim((string)($training['level'] ?? '')));
        if ($level !== '') {
            if (str_contains($level, 'FILLER')) {
                return 'filler';
            }
            if (
                str_contains($level, 'NEUROTOX')
                || $level === 'LEVEL 1'
                || $level === 'LEVEL 2'
                || str_contains($level, 'TOX')
            ) {
                return 'toxin';
            }
            if (str_contains($level, 'MICRONEEDLING')) {
                return 'microneedling';
            }
            if (str_contains($level, 'DERMAPLANING')) {
                return 'dermaplaning';
            }
            if (str_contains($level, 'PEEL')) {
                return 'chemical_peel';
            }
            if (str_contains($level, 'IV')) {
                return 'iv_therapy';
            }
        }

        if ((int)($training['fillers'] ?? 0) > 0) {
            return 'filler';
        }
        if ((int)($training['neurotoxins'] ?? 0) > 0) {
            return 'toxin';
        }

        return 'pending_selection';
    }

    private function resolveSessionDate(?array $training, ?array $session): string
    {
        $scheduledUtc = $this->legacyLocalToUtc($this->ts($training['scheduled'] ?? null));
        if ($scheduledUtc !== null) {
            return $scheduledUtc;
        }

        if ($session !== null) {
            $sessionDate = trim((string)($session['session_date'] ?? ''));
            if ($sessionDate !== '') {
                $startTime = $this->normalizeLegacyStartTime((string)($session['start_time'] ?? ''));
                $local = $sessionDate . ' ' . $startTime;
                $sessionUtc = $this->legacyLocalToUtc($local);
                if ($sessionUtc !== null) {
                    return $sessionUtc;
                }
            }
        }

        return gmdate('c');
    }

    private function resolveCourseTitle(?array $training, ?array $session): string
    {
        if ($session !== null && trim((string)($session['course_title'] ?? '')) !== '') {
            return trim((string)$session['course_title']);
        }
        if ($training !== null && trim((string)($training['title'] ?? '')) !== '') {
            return trim((string)$training['title']);
        }

        return 'Legacy model patient class';
    }

    private function buildNotes(int $legacyId, array $row, int $trainingId, string $legacyStatus): string
    {
        $chunks = ["Legacy import data_model_patient #{$legacyId}"];
        $uid = trim((string)($row['uid'] ?? ''));
        if ($uid !== '') {
            $chunks[] = "uid={$uid}";
        }
        if ($trainingId > 0) {
            $chunks[] = "training_id={$trainingId}";
        }
        if ($legacyStatus !== '') {
            $chunks[] = "legacy_status={$legacyStatus}";
        }
        $assistance = (int)($row['assistance'] ?? 0);
        $chunks[] = $assistance === 1
            ? 'legacy_assistance=checked_in'
            : 'legacy_assistance=not_checked_in';
        $notification = (int)($row['notification'] ?? 0);
        $chunks[] = $notification === 1
            ? 'legacy_notification=sent'
            : 'legacy_notification=not_sent';
        $attendanceHour = trim((string)($row['attendance_hour'] ?? ''));
        if ($attendanceHour !== '') {
            $chunks[] = 'legacy_attendance_hour=' . $attendanceHour;
        }
        $legacyNotes = trim((string)($row['notes'] ?? ''));
        if ($legacyNotes !== '') {
            $chunks[] = $legacyNotes;
        }

        return implode(' | ', $chunks);
    }

    private function insertModelPatient(PDO $target, array $data): string
    {
        $st = $target->prepare(
            'INSERT INTO public.model_patients (
                patient_user_id, full_name, email, phone, date_of_birth,
                course_title, treatment_type, session_date, status,
                course_id, course_session_id,
                photo_consent, medical_history, allergies, current_medications,
                consent_signed, consent_signed_date, waiver_signed,
                confirmed_by_admin, confirmed_by_admin_date, notes,
                created_at, updated_at
             ) VALUES (
                :patient_user_id::uuid, :full_name, :email, :phone, :date_of_birth::date,
                :course_title, :treatment_type, :session_date::timestamptz, :status,
                :course_id::uuid, :course_session_id::uuid,
                :photo_consent::boolean, :medical_history, :allergies, :current_medications,
                :consent_signed::boolean, :consent_signed_date::timestamptz, :waiver_signed::boolean,
                :confirmed_by_admin::boolean, :confirmed_by_admin_date::timestamptz, :notes,
                COALESCE(:created_at::timestamptz, now()),
                COALESCE(:updated_at::timestamptz, now())
             )
             RETURNING id::text'
        );
        $st->execute([
            ':patient_user_id' => $data['patient_user_id'],
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':date_of_birth' => $data['date_of_birth'],
            ':course_title' => $data['course_title'],
            ':treatment_type' => $data['treatment_type'],
            ':session_date' => $data['session_date'],
            ':status' => $data['status'],
            ':course_id' => $data['course_id'],
            ':course_session_id' => $data['course_session_id'],
            ':photo_consent' => $data['photo_consent'] ? 'true' : 'false',
            ':medical_history' => $data['medical_history'],
            ':allergies' => $data['allergies'],
            ':current_medications' => $data['current_medications'],
            ':consent_signed' => $data['consent_signed'] ? 'true' : 'false',
            ':consent_signed_date' => $data['consent_signed_date'],
            ':waiver_signed' => $data['waiver_signed'] ? 'true' : 'false',
            ':confirmed_by_admin' => $data['confirmed_by_admin'] ? 'true' : 'false',
            ':confirmed_by_admin_date' => $data['confirmed_by_admin_date'],
            ':notes' => $data['notes'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);
        $id = $st->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('model_patients insert did not return id');
        }

        return $id;
    }

    private function insertEnrollment(PDO $target, array $data): ?string
    {
        $st = $target->prepare(
            'INSERT INTO public.model_patient_session_enrollments (
                model_patient_id, course_session_id, course_id, patient_user_id,
                treatment_type, status, created_at, updated_at
             ) VALUES (
                :model_patient_id::uuid, :course_session_id::uuid, :course_id::uuid, :patient_user_id::uuid,
                :treatment_type, :status, now(), now()
             )
             ON CONFLICT (model_patient_id) DO UPDATE SET
                course_session_id = EXCLUDED.course_session_id,
                course_id = EXCLUDED.course_id,
                patient_user_id = EXCLUDED.patient_user_id,
                treatment_type = EXCLUDED.treatment_type,
                status = EXCLUDED.status,
                updated_at = now()
             RETURNING id::text'
        );
        $st->execute([
            ':model_patient_id' => $data['model_patient_id'],
            ':course_session_id' => $data['course_session_id'],
            ':course_id' => $data['course_id'],
            ':patient_user_id' => $data['patient_user_id'],
            ':treatment_type' => $data['treatment_type'],
            ':status' => $data['status'],
        ]);
        $id = $st->fetchColumn();

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function promoteUserProfile(PDO $target, string $authUserId): void
    {
        $target->prepare(
            'UPDATE public.user_profiles
             SET app_role = \'model_patient\',
                 is_model_patient = true,
                 onboarding_status = COALESCE(onboarding_status, \'completed\'),
                 updated_at = now()
             WHERE user_id = :uid::uuid
               AND app_role IN (\'none\', \'patient\', \'model_patient\')'
        )->execute([':uid' => $authUserId]);
    }

    private function insertMap(
        PDO $target,
        int $legacyId,
        string $modelPatientId,
        ?string $enrollmentId,
        int $trainingId,
        ?array $session,
        ?string $patientUserId
    ): void {
        $st = $target->prepare(
            'INSERT INTO public.migration_model_patients_map (
                legacy_data_model_patient_id, model_patient_id, model_patient_session_enrollment_id,
                legacy_training_id, course_session_id, course_id, patient_user_id, migrated_at
             ) VALUES (
                :legacy_id, :model_patient_id::uuid, :enrollment_id::uuid,
                :legacy_training_id, :course_session_id::uuid, :course_id::uuid, :patient_user_id::uuid, now()
             )
             ON CONFLICT (legacy_data_model_patient_id) DO UPDATE SET
                model_patient_id = EXCLUDED.model_patient_id,
                model_patient_session_enrollment_id = EXCLUDED.model_patient_session_enrollment_id,
                legacy_training_id = EXCLUDED.legacy_training_id,
                course_session_id = EXCLUDED.course_session_id,
                course_id = EXCLUDED.course_id,
                patient_user_id = EXCLUDED.patient_user_id,
                migrated_at = now()'
        );
        $st->execute([
            ':legacy_id' => $legacyId,
            ':model_patient_id' => $modelPatientId,
            ':enrollment_id' => $enrollmentId,
            ':legacy_training_id' => $trainingId > 0 ? $trainingId : null,
            ':course_session_id' => $session['course_session_id'] ?? null,
            ':course_id' => $session['course_id'] ?? null,
            ':patient_user_id' => $patientUserId,
        ]);
    }

    private function deleteExistingMapRow(PDO $target, int $legacyId): void
    {
        $st = $target->prepare(
            'SELECT model_patient_id::text, model_patient_session_enrollment_id::text
             FROM public.migration_model_patients_map
             WHERE legacy_data_model_patient_id = :id
             LIMIT 1'
        );
        $st->execute([':id' => $legacyId]);
        $existing = $st->fetch();
        if (!$existing) {
            return;
        }

        if (!empty($existing['model_patient_session_enrollment_id'])) {
            $target->prepare(
                'DELETE FROM public.model_patient_session_enrollments WHERE id = :id::uuid'
            )->execute([':id' => $existing['model_patient_session_enrollment_id']]);
        }
        if (!empty($existing['model_patient_id'])) {
            $target->prepare(
                'DELETE FROM public.model_patients WHERE id = :id::uuid'
            )->execute([':id' => $existing['model_patient_id']]);
        }
        $target->prepare(
            'DELETE FROM public.migration_model_patients_map WHERE legacy_data_model_patient_id = :id'
        )->execute([':id' => $legacyId]);
    }

    private function mapExists(PDO $target, int $legacyId): bool
    {
        $st = $target->prepare(
            'SELECT 1 FROM public.migration_model_patients_map WHERE legacy_data_model_patient_id = :id LIMIT 1'
        );
        $st->execute([':id' => $legacyId]);

        return (bool)$st->fetchColumn();
    }

    private function ensureMapTable(PDO $target): void
    {
        $sql = @file_get_contents(ROOT . DS . 'sql' . DS . 'migration_model_patients_map.sql');
        if (is_string($sql) && $sql !== '') {
            $target->exec($sql);
        }
    }

    private function ensureCheckpointTable(PDO $target): void
    {
        $target->exec('
            CREATE TABLE IF NOT EXISTS public.migration_checkpoints (
              name text PRIMARY KEY,
              last_legacy_id bigint NOT NULL,
              updated_at timestamptz NOT NULL DEFAULT now()
            )
        ');
    }

    private function checkpointGet(PDO $target, string $name): ?int
    {
        $st = $target->prepare('SELECT last_legacy_id FROM public.migration_checkpoints WHERE name = :n LIMIT 1');
        $st->execute([':n' => $name]);
        $v = $st->fetchColumn();

        return $v !== false ? (int)$v : null;
    }

    private function checkpointSet(PDO $target, string $name, int $lastId): void
    {
        $st = $target->prepare(
            'INSERT INTO public.migration_checkpoints (name, last_legacy_id, updated_at)
             VALUES (:n, :id, now())
             ON CONFLICT (name) DO UPDATE SET last_legacy_id = EXCLUDED.last_legacy_id, updated_at = now()'
        );
        $st->execute([':n' => $name, ':id' => $lastId]);
    }

    private function ts($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }

        return $v;
    }

    private function resolveEnvLabel(string $configPath): string
    {
        // Match config filename only — avoid false positives from paths like ".../myspa-live/...".
        $base = strtolower(basename($configPath));

        return $base === 'migration_sys_users_live.php' ? 'LIVE' : 'dev';
    }

    private function legacyLocalToUtc(?string $localDateTime): ?string
    {
        $local = $this->ts($localDateTime);
        if ($local === null) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($local, new \DateTimeZone($this->legacyTimezone));

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeLegacyStartTime(string $startTime): string
    {
        $startTime = trim($startTime);
        if ($startTime === '') {
            return '12:00:00';
        }

        $parsed = strtotime($startTime);
        if ($parsed !== false) {
            return date('H:i:s', $parsed);
        }

        return '12:00:00';
    }

    /**
     * @return resource|false
     */
    private function openDuplicatesCsv(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $writeHeader = !is_file($path) || (int)@filesize($path) === 0;
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            return false;
        }
        
        if ($writeHeader) {
            fputcsv($fh, [
                'skipped_at',
                'dry_run',
                'legacy_data_model_patient_id',
                'email',
                'full_name',
                'legacy_status',
                'requested_training_id',
                'registered_training_id',
                'legacy_training_id',
                'patient_user_id',
                'course_session_id',
                'course_id',
                'existing_enrollment_id',
                'existing_model_patient_id',
                'existing_legacy_data_model_patient_id',
                'skip_reason',
            ]);
        }

        return $fh;
    }

    /**
     * @param resource|false $fh
     * @param array<string, mixed> $row
     */
    private function logDuplicateSkip($fh, array $row): void
    {
        if (!is_resource($fh)) {
            return;
        }

        fputcsv($fh, [
            gmdate('c'),
            $row['dry_run'] ?? '',
            $row['legacy_data_model_patient_id'] ?? '',
            $row['email'] ?? '',
            $row['full_name'] ?? '',
            $row['legacy_status'] ?? '',
            $row['requested_training_id'] ?? '',
            $row['registered_training_id'] ?? '',
            $row['legacy_training_id'] ?? '',
            $row['patient_user_id'] ?? '',
            $row['course_session_id'] ?? '',
            $row['course_id'] ?? '',
            $row['existing_enrollment_id'] ?? '',
            $row['existing_model_patient_id'] ?? '',
            $row['existing_legacy_data_model_patient_id'] ?? '',
            $row['skip_reason'] ?? '',
        ]);
    }

    private function enrollmentDedupeKey(
        string $courseSessionId,
        string $courseId,
        string $patientUserId,
        string $status
    ): string {
        return $courseSessionId . '|' . $courseId . '|' . $patientUserId . '|' . $status;
    }

    /**
     * Skip only when session + course + auth user + mapped status all match an existing row.
     *
     * @param array<string, mixed>|null $session
     * @param array<string, int> $seenSessionPatient legacy id keyed by session|course|patient|status
     * @return array<string, mixed>|null
     */
    private function resolveDuplicateEnrollmentSkip(
        PDO $target,
        ?array $session,
        ?string $patientUserId,
        string $status,
        array $seenSessionPatient
    ): ?array {
        if ($session === null || $patientUserId === null || $patientUserId === '') {
            return null;
        }

        $sessionId = (string)($session['course_session_id'] ?? '');
        $courseId = (string)($session['course_id'] ?? '');
        if ($sessionId === '' || $courseId === '') {
            return null;
        }

        $key = $this->enrollmentDedupeKey($sessionId, $courseId, $patientUserId, $status);
        if (isset($seenSessionPatient[$key])) {
            return [
                'enrollment_id' => '',
                'model_patient_id' => '',
                'legacy_data_model_patient_id' => (string)$seenSessionPatient[$key],
                'skip_reason' => 'duplicate_session_patient_same_run',
            ];
        }

        $existing = $this->findExistingSessionEnrollment($target, $sessionId, $patientUserId, $courseId, $status);
        if ($existing === null) {
            return null;
        }

        $existing['skip_reason'] = 'duplicate_session_patient_existing';

        return $existing;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingSessionEnrollment(
        PDO $target,
        string $sessionId,
        string $patientUserId,
        ?string $courseId = null,
        ?string $status = null
    ): ?array {
        $sql = 'SELECT e.id::text AS enrollment_id,
                       e.model_patient_id::text AS model_patient_id,
                       m.legacy_data_model_patient_id
                FROM public.model_patient_session_enrollments e
                LEFT JOIN public.migration_model_patients_map m ON m.model_patient_id = e.model_patient_id
                WHERE e.course_session_id = :session_id::uuid
                  AND e.patient_user_id = :patient_user_id::uuid';
        $params = [
            ':session_id' => $sessionId,
            ':patient_user_id' => $patientUserId,
        ];
        if ($courseId !== null && $courseId !== '') {
            $sql .= ' AND e.course_id = :course_id::uuid';
            $params[':course_id'] = $courseId;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND e.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' LIMIT 1';

        $st = $target->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }

        return [
            'enrollment_id' => (string)($row['enrollment_id'] ?? ''),
            'model_patient_id' => (string)($row['model_patient_id'] ?? ''),
            'legacy_data_model_patient_id' => $row['legacy_data_model_patient_id'] !== null
                ? (string)$row['legacy_data_model_patient_id']
                : '',
        ];
    }

    private function isDuplicateSessionEnrollmentError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'uq_model_patient_session_enrollments_session_patient');
    }

    private function pdoMysql(array $cfg): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)($cfg['port'] ?? 3306), $cfg['database']),
            (string)$cfg['username'],
            (string)$cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function pdoPg(array $cfg): PDO
    {
        $dsn = (string)($cfg['dsn'] ?? '');
        if ($dsn === '') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $cfg['host'],
                (int)($cfg['port'] ?? 5432),
                $cfg['database'],
                (string)($cfg['sslmode'] ?? 'require')
            );
        }

        return new PDO($dsn, (string)$cfg['username'], (string)$cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
