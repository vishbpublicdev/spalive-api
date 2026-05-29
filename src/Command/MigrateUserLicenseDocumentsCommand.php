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
 * Phase C: legacy sys_licences (+ front/back blobs) -> public.provider_licenses + Supabase Storage.
 *
 * Target schema (temp DB jedwodtuaahriilwlyrf):
 *   - public.user_profiles — no license_* columns (licenses moved off profile)
 *   - public.provider_licenses — one row per legacy sys_licences record
 *     (document_url_front / document_url_back only; run sql/provider_licenses_document_url_front_back_manual.sql first)
 *   - public.migration_provider_licenses_map — idempotency + rollback (see sql/migration_provider_licenses_map.sql)
 *   - public.migration_sys_users_map — provider_id = user_profile_id (Phase A)
 *
 * is_primary: at most one primary row per (provider_id, license_track). Used when the app needs a
 * default license for a track (state_medical vs cosmetology). Multiple legacy licences => multiple rows.
 *
 * Config: config/migration_sys_users.php
 * Run:    bin/cake migrate_user_license_documents [--dry-run] [--batch=100]
 * Rollback: bin/cake rollback_user_license_migration
 */
class MigrateUserLicenseDocumentsCommand extends Command
{
    private const CHECKPOINT_DEFAULT = 'sys_licences:provider_licenses';

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Migrate legacy sys_licences to public.provider_licenses (Supabase Storage + map table).')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Path to migration config (legacy MySQL + target Postgres/Supabase).',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
                'help' => 'No uploads or writes.',
            ])
            ->addOption('batch', [
                'short' => 'b',
                'default' => 100,
                'help' => 'sys_licences rows per batch (default 100).',
            ])
            ->addOption('from-id', [
                'default' => 0,
                'help' => 'Start after this sys_licences.id (0 = resume from checkpoint).',
            ])
            ->addOption('max-rows', [
                'default' => 0,
                'help' => 'Stop after N licence rows examined (0 = all).',
            ])
            ->addOption('checkpoint-key', [
                'default' => self::CHECKPOINT_DEFAULT,
            ])
            ->addOption('no-resume', [
                'boolean' => true,
                'default' => false,
                'help' => 'Start from sys_licences.id>0 (ignore migration_checkpoints).',
            ])
            ->addOption('force', [
                'boolean' => true,
                'default' => false,
                'help' => 'Re-upload sides that are already mapped (storage upsert + new provider_licenses row if missing).',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $batch = max(1, (int)$args->getOption('batch'));
        $fromId = max(0, (int)$args->getOption('from-id'));
        $maxRows = max(0, (int)$args->getOption('max-rows'));
        $checkpointKey = (string)$args->getOption('checkpoint-key');
        // CakePHP boolean options are false when omitted (default=>true on boolean is ignored).
        $resume = !(bool)$args->getOption('no-resume');
        $force = (bool)$args->getOption('force');

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['legacy'], $cfg['target'])) {
            $io->err("Invalid config: {$configPath}");

            return static::CODE_ERROR;
        }

        $apiUrl = rtrim((string)($cfg['target']['api_url'] ?? ''), '/');
        $serviceKey = (string)($cfg['target']['service_role_key'] ?? '');
        $bucket = (string)($cfg['target']['storage_bucket'] ?? 'uploads');
        $prefix = trim((string)($cfg['target']['storage_object_prefix'] ?? ''), '/');
        $maxBytes = max(1024, (int)($cfg['target']['max_upload_bytes'] ?? 26214400));
        $publicUrls = (bool)($cfg['target']['storage_public_urls'] ?? true);
        $legacyFilesDir = rtrim((string)($cfg['legacy']['files_directory'] ?? ''), '/') . '/';
        if ($legacyFilesDir === '/') {
            $legacyFilesDir = '';
        }

        try {
            $legacy = $this->pdoMysql($cfg['legacy']);
            $target = $this->pdoPg($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connect failed: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        $legacyStartCol = $this->detectLegacyLicenceStartDateColumn($legacy);
        $targetStartCol = $this->detectTargetProviderLicensesStartDateColumn($target);
        if ($legacyStartCol !== null && $targetStartCol !== null) {
            $io->out("Start date migration enabled: legacy.sys_licences.{$legacyStartCol} -> public.provider_licenses.{$targetStartCol}");
        } elseif ($legacyStartCol !== null && $targetStartCol === null) {
            $io->warning("Legacy has start date column sys_licences.{$legacyStartCol}, but target provider_licenses has no start-date column; start date will NOT be migrated.");
        }

        if (!$dryRun && ($apiUrl === '' || $serviceKey === '')) {
            $io->err('Set target.api_url and target.service_role_key for storage uploads.');

            return static::CODE_ERROR;
        }

        if (!$dryRun) {
            $this->ensureMapTable($target);
            $this->ensureCheckpointTable($target);
        }

        $lastId = $fromId;
        $checkpointLoaded = null;
        if ($fromId === 0 && $resume) {
            $checkpointLoaded = $this->checkpointGet($target, $checkpointKey);
            if ($checkpointLoaded !== null && $checkpointLoaded > 0) {
                $lastId = $checkpointLoaded;
            }
        }

        if ($fromId === 0) {
            if ($resume && $checkpointLoaded !== null && $checkpointLoaded > 0) {
                $io->out("Resuming from checkpoint last_legacy_id={$checkpointLoaded}");
            } elseif ($resume) {
                $io->out('No checkpoint row found; starting from sys_licences.id > 0');
            } else {
                $io->warning('Resume disabled (--no-resume); starting from sys_licences.id > 0');
            }
        }

        $io->out(sprintf(
            'Phase C (provider_licenses) dry-run=%s batch=%d from sys_licences.id>%d checkpoint=%s force=%s',
            $dryRun ? 'yes' : 'no',
            $batch,
            $lastId,
            $checkpointKey,
            $force ? 'yes' : 'no'
        ));

        $examined = 0;
        $licencesCreated = 0;
        $filesUploaded = 0;
        $skipped = 0;
        $stop = false;

        while (!$stop) {
            $rows = $this->fetchLicenceBatch($legacy, $lastId, $batch, $legacyStartCol);
            if ($rows === []) {
                break;
            }

            if (!$dryRun) {
                $target->beginTransaction();
            }

            try {
                foreach ($rows as $row) {
                    $examined++;
                    $licenceId = (int)$row['id'];
                    $lastId = $licenceId;

                    $legacyUserId = (int)($row['user_id'] ?? 0);
                    if ($legacyUserId <= 0) {
                        $skipped++;
                        continue;
                    }

                    $providerId = $this->resolveProviderId($target, $legacyUserId);
                    if ($providerId === null) {
                        $skipped++;
                        continue;
                    }

                    $track = $this->licenseTrack((string)($row['type'] ?? ''));
                    $licenseType = $this->licenseType((string)($row['type'] ?? ''));
                    $licenseNumber = $this->licenseNumber((string)($row['number'] ?? ''), $licenceId);
                    $state = $this->licenseState((string)($row['state_code'] ?? ''));
                    $expiration = $this->dateOnly($row['exp_date'] ?? null);
                    $startDate = $this->dateOnly($row['start_date'] ?? null);
                    $verification = $this->verificationStatus((string)($row['status'] ?? ''));

                    $sides = [
                        'front' => (int)($row['front'] ?? 0),
                        'back' => (int)($row['back'] ?? 0),
                    ];

                    $uploaded = [];
                    foreach ($sides as $side => $fileId) {
                        if ($fileId <= 0) {
                            continue;
                        }
                        if (!$force && $this->mapExists($target, $licenceId, $side)) {
                            continue;
                        }

                        $blob = $this->fetchFileBlob($legacy, $fileId, $legacyFilesDir);
                        if ($blob === null) {
                            $io->warning("licence_id={$licenceId} side={$side} missing _files id={$fileId}");
                            continue;
                        }

                        $binary = $blob['data'];
                        if (!is_string($binary) || $binary === '') {
                            continue;
                        }
                        if (strlen($binary) > $maxBytes) {
                            $io->warning("licence_id={$licenceId} side={$side} file too large");
                            continue;
                        }

                        $mime = (string)($blob['mimetype'] ?? 'application/octet-stream');
                        $safeName = $this->safeFilename((string)($blob['name'] ?? 'license.bin'));
                        $storagePath = $this->storagePath($prefix, $safeName);

                        if ($dryRun) {
                            $uploaded[$side] = [
                                'path' => $storagePath,
                                'url' => "{$apiUrl}/storage/v1/object/public/{$bucket}/{$storagePath}",
                                'mime' => $mime,
                            ];
                            $filesUploaded++;
                            continue;
                        }

                        $this->storageUpload($apiUrl, $serviceKey, $bucket, $storagePath, $binary, $mime);
                        $documentUrl = $publicUrls
                            ? "{$apiUrl}/storage/v1/object/public/{$bucket}/{$storagePath}"
                            : "{$apiUrl}/storage/v1/object/{$bucket}/{$storagePath}";

                        $uploaded[$side] = [
                            'path' => $storagePath,
                            'url' => $documentUrl,
                            'mime' => $mime,
                        ];
                        $filesUploaded++;
                    }

                    if ($uploaded === []) {
                        if (!$force && $this->providerLicenseExistsForLegacy($target, $licenceId)) {
                            $skipped++;
                        } else {
                            $skipped++;
                        }
                        if ($maxRows > 0 && $examined >= $maxRows) {
                            $stop = true;
                        }
                        continue;
                    }

                    $documentUrlFront = $uploaded['front']['url'] ?? null;
                    $documentUrlBack = $uploaded['back']['url'] ?? null;
                    $adminNotes = 'Legacy import licence #' . $licenceId;

                    $isPrimary = !$this->providerTrackHasPrimary($target, $providerId, $track);

                    if ($dryRun) {
                        $licencesCreated++;
                    } else {
                        $existingPlId = $this->mappedProviderLicenseId($target, $licenceId);
                        if ($existingPlId === null) {
                            $existingPlId = $this->insertProviderLicense(
                                $target,
                                $providerId,
                                $track,
                                $licenseType,
                                $licenseNumber,
                                $state,
                                $startDate,
                                $expiration,
                                $documentUrlFront,
                                $documentUrlBack,
                                $verification,
                                $isPrimary,
                                $adminNotes,
                                $targetStartCol
                            );
                            $licencesCreated++;
                        } else {
                            $this->updateProviderLicenseDocument(
                                $target,
                                $existingPlId,
                                $documentUrlFront,
                                $documentUrlBack,
                                $adminNotes,
                                $verification,
                                $startDate,
                                $targetStartCol
                            );
                        }

                        foreach ($uploaded as $side => $meta) {
                            $this->mapInsert($target, $licenceId, $side, $existingPlId, $bucket, $meta['path']);
                        }
                    }

                    if ($maxRows > 0 && $examined >= $maxRows) {
                        $stop = true;
                        break;
                    }
                }

                if (!$dryRun) {
                    $target->commit();
                    $this->checkpointSet($target, $checkpointKey, $lastId);
                }
            } catch (Throwable $e) {
                if (!$dryRun && $target->inTransaction()) {
                    $target->rollBack();
                }
                $io->err("Phase C failed near sys_licences.id={$lastId}: " . $e->getMessage());

                return static::CODE_ERROR;
            }

            $io->out("progress examined={$examined} licences={$licencesCreated} files={$filesUploaded} skipped={$skipped} last_id={$lastId}");
            if ($stop) {
                break;
            }
        }

        $io->success(sprintf(
            'Phase C done. examined=%d licences=%d files=%d skipped=%d checkpoint_licence_id=%d',
            $examined,
            $licencesCreated,
            $filesUploaded,
            $skipped,
            $lastId
        ));

        return static::CODE_SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function fetchLicenceBatch(PDO $legacy, int $afterId, int $limit, ?string $legacyStartDateColumn): array
    {
        $startDateExpr = 'NULL AS start_date';
        if ($legacyStartDateColumn !== null) {
            // Column name is from a fixed allowlist in detectLegacyLicenceStartDateColumn()
            $startDateExpr = 'l.' . $legacyStartDateColumn . ' AS start_date';
        }
        $sql = '
            SELECT
                l.id,
                l.user_id,
                l.type,
                l.number,
                ' . $startDateExpr . ',
                l.exp_date,
                COALESCE(l.front, 0) AS front,
                COALESCE(l.back, 0) AS back,
                l.status,
                COALESCE(cs.abv, cs.name, \'\') AS state_code
            FROM sys_licences l
            LEFT JOIN cat_states cs ON cs.id = l.state
            WHERE l.id > :after_id
              AND COALESCE(l.deleted, 0) = 0
            ORDER BY l.id ASC
            LIMIT ' . (int)$limit;
        $st = $legacy->prepare($sql);
        $st->execute([':after_id' => $afterId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveProviderId(PDO $target, int $legacyUserId): ?string
    {
        $st = $target->prepare(
            'SELECT user_profile_id::text
             FROM public.migration_sys_users_map
             WHERE legacy_user_id = :uid AND user_profile_id IS NOT NULL
             LIMIT 1'
        );
        $st->execute([':uid' => $legacyUserId]);
        $id = $st->fetchColumn();

        return $id ? (string)$id : null;
    }

    private function mapExists(PDO $target, int $licenceId, string $side): bool
    {
        $st = $target->prepare(
            'SELECT 1 FROM public.migration_provider_licenses_map
             WHERE legacy_licence_id = :lid AND legacy_side = :side LIMIT 1'
        );
        $st->execute([':lid' => $licenceId, ':side' => $side]);

        return (bool)$st->fetchColumn();
    }

    private function mappedProviderLicenseId(PDO $target, int $licenceId): ?string
    {
        $st = $target->prepare(
            'SELECT provider_license_id::text
             FROM public.migration_provider_licenses_map
             WHERE legacy_licence_id = :lid
             ORDER BY CASE legacy_side WHEN \'front\' THEN 0 ELSE 1 END
             LIMIT 1'
        );
        $st->execute([':lid' => $licenceId]);
        $id = $st->fetchColumn();

        return $id ? (string)$id : null;
    }

    private function providerLicenseExistsForLegacy(PDO $target, int $licenceId): bool
    {
        return $this->mappedProviderLicenseId($target, $licenceId) !== null;
    }

    private function providerTrackHasPrimary(PDO $target, string $providerId, string $track): bool
    {
        $st = $target->prepare(
            'SELECT 1 FROM public.provider_licenses
             WHERE provider_id = :pid::uuid AND license_track = :track AND is_primary = true
             LIMIT 1'
        );
        $st->execute([':pid' => $providerId, ':track' => $track]);

        return (bool)$st->fetchColumn();
    }

    private function licenseTrack(string $legacyType): string
    {
        $t = strtoupper(trim($legacyType));
        if (in_array($t, ['ESTHETICIAN', 'COSMETOLOGY/ESTHETICIAN', 'COSMETOLOGY'], true)) {
            return 'cosmetology';
        }

        return 'state_medical';
    }

    private function licenseType(string $legacyType): string
    {
        $type = trim($legacyType);
        if ($type === '') {
            return 'Other';
        }

        $upper = strtoupper($type);
        switch ($upper) {
            case 'DOCTOR':
            case 'MEDICAL DOCTOR':
                return 'MD';
            case 'NURSE PRACTICIONER':
            case 'NURSE PRACTITIONER':
                return 'Nurse Practitioner';
            case 'NP/PA':
                return 'NP/PA';
            case 'MD':
                return 'MD';
            case 'NP':
                return 'NP';
            case 'PA':
                return 'PA';
            case 'RN':
                return 'RN';
            case 'LVN':
                return 'LVN';
            case 'MA':
                return 'MA';
            case 'CNS':
                return 'CNS';
            case 'ESTHETICIAN':
            case 'COSMETOLOGY/ESTHETICIAN':
                return 'Esthetician';
            case 'OTHER':
                return 'Other';
            default:
                return $type;
        }
    }

    private function licenseNumber(string $number, int $licenceId): string
    {
        $number = strtoupper(trim($number));
        if ($number !== '') {
            return $number;
        }

        return 'LEGACY-' . $licenceId;
    }

    private function licenseState(string $stateCode): string
    {
        $stateCode = strtoupper(trim($stateCode));
        if ($stateCode !== '' && strlen($stateCode) <= 10) {
            return $stateCode;
        }

        return 'NA';
    }

    /**
     * @param mixed $value
     */
    private function dateOnly($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function verificationStatus(string $legacyStatus): string
    {
        switch (strtoupper(trim($legacyStatus))) {
            case 'APPROVED':
                return 'approved';
            case 'REJECTED':
            case 'REJECT':
                return 'rejected';
            default:
                return 'pending';
        }
    }

    /**
     * Legacy FilesComponent stores blobs either:
     * - mysql: _files_data.id = _files.id (FILE_SOURCE=mysql, default in production app.php)
     * - files: on disk at {files_directory}{path}/{uid} (FILE_SOURCE=files)
     *
     * Join _files_data on fd.id = f.id only (_files._filedata_id is unused, always 0 in practice).
     */
    private function fetchFileBlob(PDO $legacy, int $fileId, string $filesDirectory = ''): ?array
    {
        $st = $legacy->prepare(
            'SELECT
                f.name,
                f.path,
                f.uid,
                fd.data AS data,
                mt.mimetype AS mimetype
             FROM _files f
             LEFT JOIN _files_data fd ON fd.id = f.id
             LEFT JOIN _mimetypes mt ON mt.id = f._mimetype_id
             WHERE f.id = :id
             LIMIT 1'
        );
        $st->execute([':id' => $fileId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $data = $row['data'] ?? null;
        if (!is_string($data) || $data === '') {
            $data = $this->readLegacyFileFromDisk($filesDirectory, (string)($row['path'] ?? ''), (string)($row['uid'] ?? ''));
        }

        if (!is_string($data) || $data === '') {
            return null;
        }

        return [
            'data' => $data,
            'name' => (string)($row['name'] ?? 'license.bin'),
            'mimetype' => (string)($row['mimetype'] ?? 'application/octet-stream'),
        ];
    }

    private function readLegacyFileFromDisk(string $filesDirectory, string $path, string $uid): ?string
    {
        if ($filesDirectory === '' || $path === '' || $uid === '') {
            return null;
        }

        $fullPath = $filesDirectory . $path . '/' . $uid;
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            return null;
        }

        $data = file_get_contents($fullPath);

        return is_string($data) && $data !== '' ? $data : null;
    }

    /** Lovable-style flat key: {ms}-{filename} (optional prefix from config). */
    private function storagePath(string $prefix, string $filename): string
    {
        $base = (string)(int)(microtime(true) * 1000) . '-' . $filename;
        if ($prefix === '') {
            return $base;
        }

        return $prefix . '/' . $base;
    }

    private function insertProviderLicense(
        PDO $target,
        string $providerId,
        string $track,
        string $licenseType,
        string $licenseNumber,
        string $state,
        ?string $startDate,
        ?string $expiration,
        ?string $documentUrlFront,
        ?string $documentUrlBack,
        string $verification,
        bool $isPrimary,
        ?string $adminNotes,
        ?string $targetStartDateColumn
    ): string {
        $startDateCols = '';
        $startDateVals = '';
        if ($targetStartDateColumn !== null) {
            $startDateCols = ', ' . $targetStartDateColumn;
            $startDateVals = ', :start_date::date';
        }
        $st = $target->prepare(
            'INSERT INTO public.provider_licenses (
                provider_id, license_track, license_type, license_number, state,
                expiration_date' . $startDateCols . ', document_url_front, document_url_back,
                verification_status, admin_notes, is_primary,
                created_at, updated_at
            ) VALUES (
                :provider_id::uuid, :track, :license_type, :license_number, :state,
                :expiration_date::date' . $startDateVals . ', :document_url_front, :document_url_back,
                :verification_status, :admin_notes, :is_primary,
                now(), now()
            ) RETURNING id::text'
        );
        $params = [
            ':provider_id' => $providerId,
            ':track' => $track,
            ':license_type' => $licenseType,
            ':license_number' => $licenseNumber,
            ':state' => $state,
            ':expiration_date' => $expiration,
            ':document_url_front' => $documentUrlFront,
            ':document_url_back' => $documentUrlBack,
            ':verification_status' => $verification,
            ':admin_notes' => $adminNotes,
            // Postgres boolean must be true/false, not empty string (PDO may stringify false as '').
            ':is_primary' => $isPrimary ? 'true' : 'false',
        ];
        if ($targetStartDateColumn !== null) {
            $params[':start_date'] = $startDate;
        }
        $st->execute($params);

        return (string)$st->fetchColumn();
    }

    private function updateProviderLicenseDocument(
        PDO $target,
        string $providerLicenseId,
        ?string $documentUrlFront,
        ?string $documentUrlBack,
        ?string $adminNotes,
        string $verification,
        ?string $startDate,
        ?string $targetStartDateColumn
    ): void {
        $startDateSet = '';
        if ($targetStartDateColumn !== null) {
            $startDateSet = ', ' . $targetStartDateColumn . ' = COALESCE(:start_date::date, ' . $targetStartDateColumn . ')';
        }
        $st = $target->prepare(
            'UPDATE public.provider_licenses SET
                document_url_front = :document_url_front,
                document_url_back = :document_url_back,
                admin_notes = COALESCE(:admin_notes, admin_notes),
                verification_status = :verification_status,
                updated_at = now()' . $startDateSet . '
             WHERE id = :id::uuid'
        );
        $params = [
            ':id' => $providerLicenseId,
            ':document_url_front' => $documentUrlFront,
            ':document_url_back' => $documentUrlBack,
            ':admin_notes' => $adminNotes,
            ':verification_status' => $verification,
        ];
        if ($targetStartDateColumn !== null) {
            $params[':start_date'] = $startDate;
        }
        $st->execute($params);
    }

    private function mapInsert(
        PDO $target,
        int $licenceId,
        string $side,
        string $providerLicenseId,
        string $bucket,
        string $storagePath
    ): void {
        $st = $target->prepare(
            'INSERT INTO public.migration_provider_licenses_map (
                legacy_licence_id, legacy_side, provider_license_id, storage_bucket, storage_path, created_at
            ) VALUES (
                :legacy_licence_id, :legacy_side, :provider_license_id::uuid, :storage_bucket, :storage_path, now()
            )
            ON CONFLICT (legacy_licence_id, legacy_side) DO UPDATE SET
                provider_license_id = EXCLUDED.provider_license_id,
                storage_bucket = EXCLUDED.storage_bucket,
                storage_path = EXCLUDED.storage_path'
        );
        $st->execute([
            ':legacy_licence_id' => $licenceId,
            ':legacy_side' => $side,
            ':provider_license_id' => $providerLicenseId,
            ':storage_bucket' => $bucket,
            ':storage_path' => $storagePath,
        ]);
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

    private function ensureMapTable(PDO $target): void
    {
        $target->exec('
            CREATE TABLE IF NOT EXISTS public.migration_provider_licenses_map (
              legacy_licence_id bigint NOT NULL,
              legacy_side text NOT NULL CHECK (legacy_side IN (\'front\', \'back\')),
              provider_license_id uuid NOT NULL REFERENCES public.provider_licenses (id) ON DELETE CASCADE,
              storage_bucket text NOT NULL,
              storage_path text NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (legacy_licence_id, legacy_side)
            )
        ');
    }

    private function storageUpload(
        string $apiUrl,
        string $serviceKey,
        string $bucket,
        string $path,
        string $body,
        string $contentType
    ): void {
        $url = $apiUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $this->encodePath($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $serviceKey,
                'Authorization: Bearer ' . $serviceKey,
                'Content-Type: ' . $contentType,
                'x-upsert: true',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $err !== '') {
            throw new \RuntimeException('Storage upload: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            $msg = is_string($resp) ? $resp : '';
            if ($code === 409 || str_contains(strtolower($msg), 'already exists')) {
                return;
            }
            throw new \RuntimeException("Storage upload HTTP {$code}: {$msg}");
        }
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace(["\0"], '', $name));
        $name = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $name) ?? 'file.bin';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'file.bin';
        }

        return substr($name, 0, 180);
    }

    private function checkpointGet(PDO $target, string $name): ?int
    {
        $st = $target->prepare(
            'SELECT last_legacy_id FROM public.migration_checkpoints WHERE name = :name LIMIT 1'
        );
        $st->execute([':name' => $name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['last_legacy_id'] : null;
    }

    private function checkpointSet(PDO $target, string $name, int $lastId): void
    {
        $st = $target->prepare(
            'INSERT INTO public.migration_checkpoints (name, last_legacy_id, updated_at)
             VALUES (:name, :id, now())
             ON CONFLICT (name) DO UPDATE SET last_legacy_id = EXCLUDED.last_legacy_id, updated_at = now()'
        );
        $st->execute([':name' => $name, ':id' => $lastId]);
    }

    private function pdoMysql(array $cfg): PDO
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

    private function pdoPg(array $cfg): PDO
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

    private function detectLegacyLicenceStartDateColumn(PDO $legacy): ?string
    {
        $candidates = ['start_date', 'issue_date', 'issued_date', 'effective_date', 'from_date', 'startdate'];
        $st = $legacy->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')
             LIMIT 1'
        );
        $params = array_merge([':table' => 'sys_licences'], $candidates);
        // PDO positional placeholders cannot mix with named; use positional only
        $st = $legacy->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')
             ORDER BY FIELD(column_name,' . implode(',', array_fill(0, count($candidates), '?')) . ')
             LIMIT 1'
        );
        $execParams = array_merge(['sys_licences'], $candidates, $candidates);
        $st->execute($execParams);
        $col = $st->fetchColumn();

        return $col ? (string)$col : null;
    }

    private function detectTargetProviderLicensesStartDateColumn(PDO $target): ?string
    {
        $candidates = ['start_date', 'issue_date', 'issued_date', 'effective_date'];
        $st = $target->prepare(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name = :table
               AND column_name = ANY(:candidates)
             LIMIT 1'
        );
        // Postgres PDO doesn't support binding array to ANY reliably; do a simple IN list.
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $st = $target->prepare(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = ?
               AND table_name = ?
               AND column_name IN (' . $placeholders . ')
             ORDER BY CASE column_name ' . implode(' ', array_map(
                static fn(string $c, int $i): string => 'WHEN ' . $target->quote($c) . ' THEN ' . $i,
                $candidates,
                array_keys($candidates)
            )) . ' ELSE 999 END
             LIMIT 1'
        );
        $st->execute(array_merge(['public', 'provider_licenses'], $candidates));
        $col = $st->fetchColumn();

        return $col ? (string)$col : null;
    }
}
