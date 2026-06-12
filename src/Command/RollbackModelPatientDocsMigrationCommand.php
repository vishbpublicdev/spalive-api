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
 * Roll back model patient docs migration via migration_model_patient_docs_map.
 */
class RollbackModelPatientDocsMigrationCommand extends Command
{
    private const CHECKPOINT = 'data_model_patient_docs:agreement_documents';

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Rollback model patient agreement docs migration (map + storage + agreement rows).')
            ->addOption('config', ['default' => CONFIG . 'migration_sys_users.php'])
            ->addOption('rewind-to', [
                'default' => -1,
                'help' => 'Delete map rows with legacy_data_model_patient_docs_id > this value (-1 = delete all).',
            ])
            ->addOption('dry-run', ['boolean' => true, 'default' => false])
            ->addOption('skip-storage', ['boolean' => true, 'default' => false])
            ->addOption('reset-checkpoint', ['boolean' => true, 'default' => false]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $rewindTo = (int)$args->getOption('rewind-to');
        $dryRun = (bool)$args->getOption('dry-run');
        $skipStorage = (bool)$args->getOption('skip-storage');
        $resetCheckpoint = (bool)$args->getOption('reset-checkpoint');

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        $apiUrl = rtrim((string)($cfg['target']['api_url'] ?? ''), '/');
        $serviceKey = (string)($cfg['target']['service_role_key'] ?? '');

        try {
            $target = $this->pdoPg($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connect failed: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        if ($rewindTo < 0) {
            $rewindTo = 0;
            $io->out('rewind-to=0: removing all rows from migration_model_patient_docs_map');
        }

        $st = $target->prepare(
            'SELECT legacy_data_model_patient_docs_id, agreement_document_id::text,
                    signature_storage_bucket, signature_storage_path
             FROM public.migration_model_patient_docs_map
             WHERE legacy_data_model_patient_docs_id > :rewind
             ORDER BY legacy_data_model_patient_docs_id DESC'
        );
        $st->execute([':rewind' => $rewindTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $io->out('No map rows to roll back.');

            return static::CODE_SUCCESS;
        }

        $io->out(sprintf('Rolling back %d map row(s) dry-run=%s', count($rows), $dryRun ? 'yes' : 'no'));

        $agreementIdsToMaybeDelete = [];

        foreach ($rows as $row) {
            $legacyDocId = (int)$row['legacy_data_model_patient_docs_id'];
            $agreementId = (string)($row['agreement_document_id'] ?? '');
            $bucket = (string)($row['signature_storage_bucket'] ?? '');
            $path = (string)($row['signature_storage_path'] ?? '');

            if ($dryRun) {
                $io->out("  [dry-run] legacy_doc_id={$legacyDocId} agreement_id={$agreementId}");
                continue;
            }

            if (!$skipStorage && $apiUrl !== '' && $serviceKey !== '' && $bucket !== '' && $path !== '') {
                $this->storageDelete($apiUrl, $serviceKey, $bucket, $path);
            }

            $target->prepare(
                'DELETE FROM public.migration_model_patient_docs_map
                 WHERE legacy_data_model_patient_docs_id = :id'
            )->execute([':id' => $legacyDocId]);

            if ($agreementId !== '') {
                $agreementIdsToMaybeDelete[$agreementId] = true;
            }
        }

        if (!$dryRun) {
            foreach (array_keys($agreementIdsToMaybeDelete) as $agreementId) {
                $st = $target->prepare(
                    'SELECT count(*) FROM public.migration_model_patient_docs_map
                     WHERE agreement_document_id = :id::uuid'
                );
                $st->execute([':id' => $agreementId]);
                if ((int)$st->fetchColumn() === 0) {
                    $target->prepare(
                        'DELETE FROM public.model_patient_documents WHERE id = :id::uuid'
                    )->execute([':id' => $agreementId]);
                }
            }

            if ($resetCheckpoint) {
                $target->prepare('DELETE FROM public.migration_checkpoints WHERE name = :name')
                    ->execute([':name' => self::CHECKPOINT]);
                $io->out('Checkpoint cleared.');
            } else {
                $this->checkpointSet($target, self::CHECKPOINT, $rewindTo);
            }
        }

        $io->success('Rollback complete.');

        return static::CODE_SUCCESS;
    }

    private function storageDelete(string $apiUrl, string $serviceKey, string $bucket, string $path): void
    {
        $url = $apiUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $this->encodePath($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $serviceKey,
                'Authorization: Bearer ' . $serviceKey,
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 && $code !== 204 && $code !== 404) {
            throw new \RuntimeException('Storage delete HTTP ' . $code);
        }
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
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
