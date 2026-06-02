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
 * Roll back provider_licenses migration via migration_provider_licenses_map.
 *
 * Deletes rows with legacy_licence_id > --rewind-to, removes storage objects, rewinds checkpoint.
 */
class RollbackUserLicenseMigrationCommand extends Command
{
    private const CHECKPOINT_DEFAULT = 'sys_licences:provider_licenses';

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Rollback provider_licenses migration (map + checkpoint rewind).')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
            ])
            ->addOption('rewind-to', [
                'default' => -1,
                'help' => 'Delete map rows with legacy_licence_id > this value and set checkpoint here (-1 = delete all mapped rows).',
            ])
            ->addOption('checkpoint-key', [
                'default' => self::CHECKPOINT_DEFAULT,
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('skip-storage', [
                'boolean' => true,
                'default' => false,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $configPath = (string)$args->getOption('config');
        $rewindTo = (int)$args->getOption('rewind-to');
        $checkpointKey = (string)$args->getOption('checkpoint-key');
        $dryRun = (bool)$args->getOption('dry-run');
        $skipStorage = (bool)$args->getOption('skip-storage');

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
            $io->out('rewind-to=0: removing all rows from migration_provider_licenses_map');
        }

        $st = $target->prepare(
            'SELECT legacy_licence_id, legacy_side, provider_license_id::text, storage_bucket, storage_path
             FROM public.migration_provider_licenses_map
             WHERE legacy_licence_id > :rewind
             ORDER BY legacy_licence_id DESC, legacy_side'
        );
        $st->execute([':rewind' => $rewindTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $io->warning("No map rows with legacy_licence_id > {$rewindTo}");

            return static::CODE_SUCCESS;
        }

        $io->out(sprintf('Rollback %d rows (legacy_licence_id > %d) dry-run=%s', count($rows), $rewindTo, $dryRun ? 'yes' : 'no'));

        if (!$dryRun) {
            $target->beginTransaction();
        }

        $deletedLicenseIds = [];

        try {
            foreach ($rows as $row) {
                $plId = (string)$row['provider_license_id'];
                $bucket = (string)($row['storage_bucket'] ?? '');
                $path = (string)($row['storage_path'] ?? '');

                if (!$dryRun && !$skipStorage && $apiUrl !== '' && $serviceKey !== '' && $bucket !== '' && $path !== '') {
                    $this->storageDelete($apiUrl, $serviceKey, $bucket, $path);
                }

                if (!$dryRun) {
                    $target->prepare(
                        'DELETE FROM public.migration_provider_licenses_map
                         WHERE legacy_licence_id = :lid AND legacy_side = :side'
                    )->execute([
                        ':lid' => (int)$row['legacy_licence_id'],
                        ':side' => (string)$row['legacy_side'],
                    ]);
                    $deletedLicenseIds[$plId] = true;
                }
            }

            if (!$dryRun) {
                foreach (array_keys($deletedLicenseIds) as $plId) {
                    $target->prepare('DELETE FROM public.provider_licenses WHERE id = :id::uuid')
                        ->execute([':id' => $plId]);
                }
                $this->checkpointSet($target, $checkpointKey, $rewindTo);
                $target->commit();
                $io->out("Checkpoint set to legacy_licence_id={$rewindTo}");
            }
        } catch (Throwable $e) {
            if (!$dryRun && $target->inTransaction()) {
                $target->rollBack();
            }
            $io->err('Rollback failed: ' . $e->getMessage());

            return static::CODE_ERROR;
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
}
