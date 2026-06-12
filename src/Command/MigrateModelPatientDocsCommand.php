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
 * Legacy data_model_patient_docs -> public.model_patient_documents.
 *
 * Copies html_content as-is. When legacy file_id > 0, reads signature blob from _files
 * (mysql or disk) and uploads to Supabase Storage.
 *
 * Requires on target (create manually before first run):
 *   - public.model_patient_documents
 *   - public.migration_model_patient_docs_map
 *   - migration_sys_users_map and/or auth.users for patient_user_id resolution
 *
 * Run: bin/cake migrate_model_patient_docs [--config config/migration_sys_users.php] [--dry-run]
 * Skipped rows: logs/migration_model_patient_docs_skipped_{dev|LIVE}[-dry-run].csv
 */
class MigrateModelPatientDocsCommand extends Command
{
    private const CHECKPOINT = 'data_model_patient_docs:agreement_documents';

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Migrate legacy data_model_patient_docs (HTML + GFE signatures) to Supabase.')
            ->addOption('config', ['default' => CONFIG . 'migration_sys_users.php'])
            ->addOption('dry-run', ['boolean' => true, 'default' => false])
            ->addOption('batch', ['short' => 'b', 'default' => 100])
            ->addOption('from-id', ['default' => 0])
            ->addOption('max-rows', ['default' => 0])
            ->addOption('no-resume', ['boolean' => true, 'default' => false])
            ->addOption('force', [
                'boolean' => true,
                'default' => false,
                'help' => 'Re-migrate rows already present in migration_model_patient_docs_map.',
            ])
            ->addOption('skipped-csv', [
                'default' => '',
                'help' => 'CSV log for skipped rows (default: logs/migration_model_patient_docs_skipped_{dev|LIVE}[-dry-run].csv).',
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
        $envLabel = $this->resolveEnvLabel($configPath);
        $skippedCsv = trim((string)$args->getOption('skipped-csv'));
        if ($skippedCsv === '') {
            $skippedCsv = LOGS . 'migration_model_patient_docs_skipped_' . $envLabel
                . ($dryRun ? '-dry-run' : '') . '.csv';
        }

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
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

        if (!$dryRun && ($apiUrl === '' || $serviceKey === '')) {
            $io->err('Set target.api_url and target.service_role_key for signature uploads.');

            return static::CODE_ERROR;
        }

        if (!$dryRun) {
            $stmtTimeoutMs = (int)($cfg['target']['statement_timeout_ms'] ?? 0);
            $target->exec('SET statement_timeout = ' . ($stmtTimeoutMs <= 0 ? '0' : (string)$stmtTimeoutMs));
            $this->ensureCheckpointTable($target);
        }

        $lastId = $fromId;
        if ($resume && $fromId === 0 && !$dryRun) {
            $cp = $this->checkpointGet($target, self::CHECKPOINT);
            if ($cp !== null && $cp > 0) {
                $lastId = $cp;
                $io->out("Resuming from legacy data_model_patient_docs.id > {$lastId}");
            }
        }

        $io->out(sprintf(
            'Model patient docs migration env=%s dry-run=%s batch=%d from-id>%d force=%s bucket=%s skipped-csv=%s',
            $envLabel,
            $dryRun ? 'yes' : 'no',
            $batch,
            $lastId,
            $force ? 'yes' : 'no',
            $bucket,
            $skippedCsv
        ));

        $skippedCsvHandle = $this->openSkippedCsv($skippedCsv);

        $examined = 0;
        $migrated = 0;
        $skipped = 0;
        $signaturesUploaded = 0;
        $noAuthUser = 0;
        $missingHtml = 0;
        $stop = false;

        while (!$stop) {
            $rows = $this->fetchBatch($legacy, $lastId, $batch);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $examined++;
                $legacyDocId = (int)$row['id'];
                $lastId = $legacyDocId;

                if ($maxRows > 0 && $examined > $maxRows) {
                    $stop = true;
                    break;
                }

                if (!$force && !$dryRun && $this->mapExists($target, $legacyDocId)) {
                    $this->logSkippedRecord($skippedCsvHandle, $dryRun, [
                        'legacy_doc_id' => $legacyDocId,
                        'legacy_user_id' => (int)($row['user_id'] ?? 0),
                        'doc_type' => strtoupper(trim((string)($row['type'] ?? ''))),
                        'legacy_file_id' => (int)($row['file_id'] ?? 0),
                        'skip_reason' => 'already_migrated',
                    ]);
                    $skipped++;
                    continue;
                }

                $legacyUserId = (int)($row['user_id'] ?? 0);
                $docType = strtoupper(trim((string)($row['type'] ?? '')));
                if ($legacyUserId <= 0 || !in_array($docType, ['INFO', 'GFE'], true)) {
                    $io->warning("skip legacy_doc_id={$legacyDocId}: invalid user_id or type");
                    $this->logSkippedRecord($skippedCsvHandle, $dryRun, [
                        'legacy_doc_id' => $legacyDocId,
                        'legacy_user_id' => $legacyUserId,
                        'doc_type' => $docType,
                        'legacy_file_id' => (int)($row['file_id'] ?? 0),
                        'skip_reason' => 'invalid_user_id_or_type',
                    ]);
                    $skipped++;
                    continue;
                }

                $htmlContent = (string)($row['html_content'] ?? '');
                if (trim($htmlContent) === '') {
                    $missingHtml++;
                    $io->warning("skip legacy_doc_id={$legacyDocId}: empty html_content");
                    $this->logSkippedRecord($skippedCsvHandle, $dryRun, [
                        'legacy_doc_id' => $legacyDocId,
                        'legacy_user_id' => $legacyUserId,
                        'doc_type' => $docType,
                        'legacy_file_id' => (int)($row['file_id'] ?? 0),
                        'skip_reason' => 'empty_html_content',
                    ]);
                    $skipped++;
                    continue;
                }

                $patientUserId = $this->resolvePatientUserId($target, $legacy, $legacyUserId);
                if ($patientUserId === null) {
                    $noAuthUser++;
                    $io->warning("skip legacy_doc_id={$legacyDocId}: no auth user for legacy sys_users.id={$legacyUserId}");
                    $this->logSkippedRecord($skippedCsvHandle, $dryRun, [
                        'legacy_doc_id' => $legacyDocId,
                        'legacy_user_id' => $legacyUserId,
                        'doc_type' => $docType,
                        'legacy_file_id' => (int)($row['file_id'] ?? 0),
                        'skip_reason' => 'no_auth_user',
                    ]);
                    $skipped++;
                    continue;
                }

                $legacyFileId = (int)($row['file_id'] ?? 0);
                $signedAt = $this->ts($row['created'] ?? null);

                $signatureUrl = null;
                $signatureBucket = null;
                $signaturePath = null;
                $signatureMime = null;

                if ($legacyFileId > 0) {
                    $blob = $this->fetchFileBlob($legacy, $legacyFileId, $legacyFilesDir);
                    if ($blob === null) {
                        $io->warning("legacy_doc_id={$legacyDocId}: missing signature _files id={$legacyFileId}");
                    } else {
                        $binary = $blob['data'];
                        if (!is_string($binary) || $binary === '') {
                            $io->warning("legacy_doc_id={$legacyDocId}: empty signature blob file_id={$legacyFileId}");
                        } elseif (strlen($binary) > $maxBytes) {
                            $io->warning("legacy_doc_id={$legacyDocId}: signature too large file_id={$legacyFileId}");
                        } else {
                            $signatureMime = (string)($blob['mimetype'] ?? 'image/jpeg');
                            $ext = $this->extensionForMime($signatureMime);
                            $storagePath = $this->storagePath(
                                $prefix,
                                'migration/model-patient-docs/' . $legacyDocId . '/signature.' . $ext
                            );

                            if ($dryRun) {
                                $signatureBucket = $bucket;
                                $signaturePath = $storagePath;
                                $signatureUrl = $publicUrls
                                    ? "{$apiUrl}/storage/v1/object/public/{$bucket}/{$storagePath}"
                                    : "{$apiUrl}/storage/v1/object/{$bucket}/{$storagePath}";
                                $signaturesUploaded++;
                            } else {
                                $this->storageUpload($apiUrl, $serviceKey, $bucket, $storagePath, $binary, $signatureMime);
                                $signatureBucket = $bucket;
                                $signaturePath = $storagePath;
                                $signatureUrl = $publicUrls
                                    ? "{$apiUrl}/storage/v1/object/public/{$bucket}/{$storagePath}"
                                    : "{$apiUrl}/storage/v1/object/{$bucket}/{$storagePath}";
                                $signaturesUploaded++;
                            }
                        }
                    }
                }

                if ($dryRun) {
                    $io->out(sprintf(
                        '  [dry-run] legacy_doc_id=%d user=%s type=%s html_len=%d signature=%s',
                        $legacyDocId,
                        $patientUserId,
                        $docType,
                        strlen($htmlContent),
                        $signaturePath ?? 'none'
                    ));
                    $migrated++;
                    continue;
                }

                try {
                    $target->beginTransaction();

                    $agreementId = $this->upsertAgreementDocument(
                        $target,
                        $patientUserId,
                        $docType,
                        $htmlContent,
                        $legacyUserId,
                        $legacyFileId,
                        $signatureUrl,
                        $signatureBucket,
                        $signaturePath,
                        $signatureMime,
                        $signedAt
                    );

                    $this->insertMap(
                        $target,
                        $legacyDocId,
                        $agreementId,
                        $legacyUserId,
                        $patientUserId,
                        $docType,
                        $legacyFileId,
                        $signatureBucket,
                        $signaturePath
                    );

                    $target->commit();
                    $this->checkpointSet($target, self::CHECKPOINT, $legacyDocId);
                    $migrated++;
                } catch (Throwable $e) {
                    if ($target->inTransaction()) {
                        $target->rollBack();
                    }
                    if (is_resource($skippedCsvHandle)) {
                        fclose($skippedCsvHandle);
                    }
                    $io->err("Failed legacy_doc_id={$legacyDocId}: " . $e->getMessage());

                    return static::CODE_ERROR;
                }
            }
        }

        if (is_resource($skippedCsvHandle)) {
            fclose($skippedCsvHandle);
        }

        $io->out(sprintf(
            'Done. examined=%d migrated=%d skipped=%d no_auth_user=%d missing_html=%d signatures_uploaded=%d checkpoint_id=%d',
            $examined,
            $migrated,
            $skipped,
            $noAuthUser,
            $missingHtml,
            $signaturesUploaded,
            $lastId
        ));
        if ($skipped > 0) {
            $io->out("Skipped rows CSV: {$skippedCsv}");
        }

        return static::CODE_SUCCESS;
    }

    private function resolveEnvLabel(string $configPath): string
    {
        $base = strtolower(basename($configPath));

        return $base === 'migration_sys_users_live.php' ? 'LIVE' : 'dev';
    }

    /**
     * @return resource|false
     */
    private function openSkippedCsv(string $path)
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
                'legacy_doc_id',
                'legacy_user_id',
                'doc_type',
                'legacy_file_id',
                'skip_reason',
            ]);
        }

        return $fh;
    }

    /**
     * @param resource|false $fh
     * @param array<string, mixed> $row
     */
    private function logSkippedRecord($fh, bool $dryRun, array $row): void
    {
        if (!is_resource($fh)) {
            return;
        }

        fputcsv($fh, [
            gmdate('c'),
            $dryRun ? 'yes' : 'no',
            $row['legacy_doc_id'] ?? '',
            $row['legacy_user_id'] ?? '',
            $row['doc_type'] ?? '',
            $row['legacy_file_id'] ?? '',
            $row['skip_reason'] ?? '',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchBatch(PDO $legacy, int $afterId, int $limit): array
    {
        $st = $legacy->prepare(
            'SELECT id, user_id, html_content, type, file_id, created
             FROM data_model_patient_docs
             WHERE id > :after_id AND deleted = 0
             ORDER BY id ASC
             LIMIT ' . (int)$limit
        );
        $st->execute([':after_id' => $afterId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolvePatientUserId(PDO $target, PDO $legacy, int $legacyUserId): ?string
    {
        $st = $target->prepare(
            'SELECT auth_user_id::text
             FROM public.migration_sys_users_map
             WHERE legacy_user_id = :uid AND auth_user_id IS NOT NULL
             LIMIT 1'
        );
        $st->execute([':uid' => $legacyUserId]);
        $id = $st->fetchColumn();
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $st = $legacy->prepare(
            'SELECT email FROM sys_users WHERE id = :id AND COALESCE(deleted, 0) = 0 LIMIT 1'
        );
        $st->execute([':id' => $legacyUserId]);
        $email = strtolower(trim((string)$st->fetchColumn()));
        if ($email === '') {
            return null;
        }

        $st = $target->prepare(
            'SELECT id::text FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1'
        );
        $st->execute([':email' => $email]);
        $authId = $st->fetchColumn();

        return is_string($authId) && $authId !== '' ? $authId : null;
    }

    private function upsertAgreementDocument(
        PDO $target,
        string $patientUserId,
        string $docType,
        string $htmlContent,
        int $legacyUserId,
        int $legacyFileId,
        ?string $signatureUrl,
        ?string $signatureBucket,
        ?string $signaturePath,
        ?string $signatureMime,
        ?string $signedAt
    ): string {
        $st = $target->prepare(
            'INSERT INTO public.model_patient_documents (
                patient_user_id, doc_type, html_content, legacy_sys_user_id, legacy_file_id,
                signature_url, signature_storage_bucket, signature_storage_path, signature_mime_type,
                signed_at, created_at, updated_at
            ) VALUES (
                :patient_user_id::uuid, :doc_type, :html_content, :legacy_sys_user_id, :legacy_file_id,
                :signature_url, :signature_storage_bucket, :signature_storage_path, :signature_mime_type,
                :signed_at::timestamptz, COALESCE(:signed_at::timestamptz, now()), now()
            )
            ON CONFLICT (patient_user_id, doc_type) DO UPDATE SET
                html_content = EXCLUDED.html_content,
                legacy_sys_user_id = EXCLUDED.legacy_sys_user_id,
                legacy_file_id = EXCLUDED.legacy_file_id,
                signature_url = COALESCE(EXCLUDED.signature_url, model_patient_documents.signature_url),
                signature_storage_bucket = COALESCE(EXCLUDED.signature_storage_bucket, model_patient_documents.signature_storage_bucket),
                signature_storage_path = COALESCE(EXCLUDED.signature_storage_path, model_patient_documents.signature_storage_path),
                signature_mime_type = COALESCE(EXCLUDED.signature_mime_type, model_patient_documents.signature_mime_type),
                signed_at = COALESCE(EXCLUDED.signed_at, model_patient_documents.signed_at),
                updated_at = now()
            RETURNING id::text'
        );
        $st->execute([
            ':patient_user_id' => $patientUserId,
            ':doc_type' => $docType,
            ':html_content' => $htmlContent,
            ':legacy_sys_user_id' => $legacyUserId,
            ':legacy_file_id' => $legacyFileId,
            ':signature_url' => $signatureUrl,
            ':signature_storage_bucket' => $signatureBucket,
            ':signature_storage_path' => $signaturePath,
            ':signature_mime_type' => $signatureMime,
            ':signed_at' => $signedAt,
        ]);

        return (string)$st->fetchColumn();
    }

    private function insertMap(
        PDO $target,
        int $legacyDocId,
        string $agreementId,
        int $legacyUserId,
        string $patientUserId,
        string $docType,
        int $legacyFileId,
        ?string $signatureBucket,
        ?string $signaturePath
    ): void {
        $st = $target->prepare(
            'INSERT INTO public.migration_model_patient_docs_map (
                legacy_data_model_patient_docs_id, agreement_document_id,
                legacy_sys_user_id, patient_user_id, doc_type, legacy_file_id,
                signature_storage_bucket, signature_storage_path, migrated_at
            ) VALUES (
                :legacy_doc_id, :agreement_id::uuid,
                :legacy_sys_user_id, :patient_user_id::uuid, :doc_type, :legacy_file_id,
                :signature_storage_bucket, :signature_storage_path, now()
            )
            ON CONFLICT (legacy_data_model_patient_docs_id) DO UPDATE SET
                agreement_document_id = EXCLUDED.agreement_document_id,
                legacy_sys_user_id = EXCLUDED.legacy_sys_user_id,
                patient_user_id = EXCLUDED.patient_user_id,
                doc_type = EXCLUDED.doc_type,
                legacy_file_id = EXCLUDED.legacy_file_id,
                signature_storage_bucket = EXCLUDED.signature_storage_bucket,
                signature_storage_path = EXCLUDED.signature_storage_path,
                migrated_at = now()'
        );
        $st->execute([
            ':legacy_doc_id' => $legacyDocId,
            ':agreement_id' => $agreementId,
            ':legacy_sys_user_id' => $legacyUserId,
            ':patient_user_id' => $patientUserId,
            ':doc_type' => $docType,
            ':legacy_file_id' => $legacyFileId,
            ':signature_storage_bucket' => $signatureBucket,
            ':signature_storage_path' => $signaturePath,
        ]);
    }

    private function mapExists(PDO $target, int $legacyDocId): bool
    {
        $st = $target->prepare(
            'SELECT 1 FROM public.migration_model_patient_docs_map
             WHERE legacy_data_model_patient_docs_id = :id LIMIT 1'
        );
        $st->execute([':id' => $legacyDocId]);

        return (bool)$st->fetchColumn();
    }

    /**
     * @return array{data: string, name: string, mimetype: string}|null
     */
    private function fetchFileBlob(PDO $legacy, int $fileId, string $filesDirectory): ?array
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
            $data = $this->readLegacyFileFromDisk(
                $filesDirectory,
                (string)($row['path'] ?? ''),
                (string)($row['uid'] ?? '')
            );
        }

        if (!is_string($data) || $data === '') {
            return null;
        }

        return [
            'data' => $data,
            'name' => (string)($row['name'] ?? 'signature.bin'),
            'mimetype' => (string)($row['mimetype'] ?? 'image/jpeg'),
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

    private function storagePath(string $prefix, string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($prefix === '') {
            return $relativePath;
        }

        return $prefix . '/' . $relativePath;
    }

    private function extensionForMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/png') {
            return 'png';
        }
        if ($mime === 'image/gif') {
            return 'gif';
        }
        if ($mime === 'image/webp') {
            return 'webp';
        }

        return 'jpg';
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

    /**
     * @param mixed $value
     */
    private function ts($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
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
