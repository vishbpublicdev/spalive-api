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
 * Roll back recently migrated sys_users based on public.migration_sys_users_map.
 * Deletes: public.patients, public.user_profiles, public.legacy_password_migration, map row
 * Then deletes auth user via Supabase Admin API.
 */
class RollbackLastMigratedSysUsersCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Rollback last migrated sys_users (by migrated_at) from target DB + delete auth users via Admin API.')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Absolute path to migration config file.',
            ])
            ->addOption('dry-run', [
                // Intentionally NOT a boolean flag: Cake treats "--dry-run=0" as "present=true" if boolean.
                // We accept 0/1, true/false, yes/no and parse manually.
                'default' => '1',
                'help' => 'If enabled, only prints which users would be deleted. Accepts 1/0, true/false, yes/no. (default 1)',
            ])
            ->addOption('count', [
                'short' => 'n',
                'default' => 5,
                'help' => 'How many most-recent migrated users to delete (default 5).',
            ])
            ->addOption('since', [
                'default' => '',
                'help' => 'Only delete rows with migrated_at >= this timestamp (e.g. 2026-04-27 or 2026-04-27T00:00:00Z).',
            ])
            ->addOption('no-sync-checkpoints', [
                'boolean' => true,
                'default' => false,
                'help' => 'If set, do not realign migration_checkpoints after rollback (not recommended for ASC migrate).',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = $this->toBool($args->getOption('dry-run'), true);
        $count = max(1, (int)$args->getOption('count'));
        $since = trim((string)$args->getOption('since'));
        $noSyncCheckpoints = (bool)$args->getOption('no-sync-checkpoints');

        if (!is_file($configPath)) {
            $io->err("Migration config not found: {$configPath}");
            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['target'])) {
            $io->err("Invalid migration config format in: {$configPath}");
            return static::CODE_ERROR;
        }

        $supabaseApiUrl = rtrim((string)($cfg['target']['api_url'] ?? ''), '/');
        $supabaseServiceRoleKey = (string)($cfg['target']['service_role_key'] ?? '');

        if (!$dryRun && ($supabaseApiUrl === '' || $supabaseServiceRoleKey === '')) {
            $io->err('Missing Supabase Admin API config. Set target.api_url + target.service_role_key.');
            return static::CODE_ERROR;
        }

        try {
            $target = $this->makePgPdo($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connection failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }

        $where = '';
        $params = [':limit' => $count];
        if ($since !== '') {
            $where = 'WHERE migrated_at >= :since::timestamptz';
            $params[':since'] = $since;
        }

        $stmt = $target->prepare(
            "SELECT legacy_user_id, legacy_email, auth_user_id, user_profile_id, mapped_app_role, migrated_at
             FROM public.migration_sys_users_map
             {$where}
             ORDER BY migrated_at DESC
             LIMIT :limit"
        );
        foreach ($params as $k => $v) {
            if ($k === ':limit') {
                $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($k, $v);
            }
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $io->out('No migrated users found to rollback.');
            return static::CODE_SUCCESS;
        }

        $io->out('Will rollback these migrated users (most recent first):');
        foreach ($rows as $r) {
            $io->out(sprintf(
                '- legacy_user_id=%s email=%s auth_user_id=%s role=%s migrated_at=%s',
                (string)$r['legacy_user_id'],
                (string)$r['legacy_email'],
                (string)$r['auth_user_id'],
                (string)$r['mapped_app_role'],
                (string)$r['migrated_at']
            ));
        }

        if ($dryRun) {
            $io->out('Dry-run enabled. No deletes executed.');
            return static::CODE_SUCCESS;
        }

        $smallestLegacyRolled = PHP_INT_MAX;
        foreach ($rows as $r0) {
            $smallestLegacyRolled = min($smallestLegacyRolled, (int)$r0['legacy_user_id']);
        }

        $deleted = 0;
        foreach ($rows as $r) {
            $legacyUserId = (int)$r['legacy_user_id'];
            $authUserId = (string)$r['auth_user_id'];

            try {
                $target->beginTransaction();

                // delete dependent public records first (avoid FK issues)
                $delPatients = $target->prepare("DELETE FROM public.patients WHERE patient_user_id = :uid::uuid");
                $delPatients->execute([':uid' => $authUserId]);

                $delLegacyPw = $target->prepare("DELETE FROM public.legacy_password_migration WHERE user_id = :uid::uuid");
                $delLegacyPw->execute([':uid' => $authUserId]);

                $delProfiles = $target->prepare("DELETE FROM public.user_profiles WHERE user_id = :uid::uuid");
                $delProfiles->execute([':uid' => $authUserId]);

                $delMap = $target->prepare("DELETE FROM public.migration_sys_users_map WHERE legacy_user_id = :lid");
                $delMap->execute([':lid' => $legacyUserId]);

                $target->commit();

                // finally delete auth user via Admin API
                $this->deleteAuthUserViaApi($supabaseApiUrl, $supabaseServiceRoleKey, $authUserId);

                $deleted++;
                $io->out("Rolled back legacy_user_id={$legacyUserId} auth_user_id={$authUserId}");
            } catch (Throwable $e) {
                if ($target->inTransaction()) {
                    $target->rollBack();
                }
                $io->err("Rollback failed for legacy_user_id={$legacyUserId} auth_user_id={$authUserId}: " . $e->getMessage());
                return static::CODE_ERROR;
            }
        }

        if (!$noSyncCheckpoints) {
            try {
                $minRolled = $smallestLegacyRolled !== PHP_INT_MAX ? $smallestLegacyRolled : null;
                $this->syncCheckpointsAfterRollback($target, $io, $minRolled);
            } catch (Throwable $e) {
                $io->err('Checkpoint sync failed (map may be inconsistent with resume): ' . $e->getMessage());
                return static::CODE_ERROR;
            }
        }

        $io->success("Rollback complete. Deleted={$deleted}");
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

    private function setCheckpoint(PDO $target, string $name, int $lastLegacyId): void
    {
        $stmt = $target->prepare(
            "INSERT INTO public.migration_checkpoints (name, last_legacy_id, updated_at)
             VALUES (:name, :id, now())
             ON CONFLICT (name) DO UPDATE SET last_legacy_id = EXCLUDED.last_legacy_id, updated_at = now()"
        );
        $stmt->execute([':name' => $name, ':id' => $lastLegacyId]);
    }

    /**
     * ASC Phase A resumes with legacy id > checkpoint. Rolling back removes map rows whose legacy ids are
     * often *below* MAX(remaining map). Setting checkpoint to MAX(map) would skip those ids forever.
     * Rewind both cursors to (smallest rolled legacy id - 1) so the next run can see them again.
     */
    private function syncCheckpointsAfterRollback(PDO $target, ConsoleIo $io, ?int $smallestLegacyRolled): void
    {
        $this->ensureCheckpointTable($target);
        if ($smallestLegacyRolled === null || $smallestLegacyRolled < 1) {
            return;
        }
        $rewindTo = max(0, $smallestLegacyRolled - 1);
        $this->setCheckpoint($target, 'sys_users:auth_profiles', $rewindTo);
        $this->setCheckpoint($target, 'sys_users:password_map', $rewindTo);
        $io->out(sprintf(
            'Synced migration_checkpoints (auth_profiles + password_map) to last_legacy_id=%d (rollback rewind; smallest rolled legacy_user_id=%d).',
            $rewindTo,
            $smallestLegacyRolled
        ));
    }

    private function deleteAuthUserViaApi(string $supabaseApiUrl, string $serviceRoleKey, string $userId): void
    {
        $ch = curl_init($supabaseApiUrl . '/auth/v1/admin/users/' . rawurlencode($userId));
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize curl for Supabase Admin API (delete).');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $serviceRoleKey,
                'Authorization: Bearer ' . $serviceRoleKey,
                'Content-Type: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            throw new \RuntimeException('Supabase Admin API delete user failed: ' . $curlErr);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return;
        }

        // if already deleted, accept
        if ($httpCode === 404) {
            return;
        }

        throw new \RuntimeException('Supabase Admin API delete user failed [' . $httpCode . ']: ' . (string)$body);
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

    private function toBool($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string)$value));
        if ($v === '') {
            return $default;
        }
        if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
        return $default;
    }
}

