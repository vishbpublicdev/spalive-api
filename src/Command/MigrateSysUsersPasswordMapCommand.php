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
 * Phase B: sys_users -> public.legacy_password_migration
 * Uses public.migration_sys_users_map to locate the correct auth_user_id.
 * Resumable via public.migration_checkpoints and idempotent via upsert.
 */
class MigrateSysUsersPasswordMapCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Phase B: migrate legacy password hashes into public.legacy_password_migration. Resumable and idempotent.')
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
                'default' => 1000,
                'help' => 'Batch size (default 1000).',
            ])
            ->addOption('from-id', [
                'default' => 0,
                'help' => 'For ASC order: start from legacy id > from-id (0 = resume from checkpoint if enabled).',
            ])
            ->addOption('max-rows', [
                'default' => 0,
                'help' => 'Stop after N rows (0 means no limit).',
            ])
            ->addOption('checkpoint-key', [
                'default' => 'sys_users:password_map',
                'help' => 'Checkpoint name used to persist last migrated legacy id.',
            ])
            ->addOption('auth-checkpoint-key', [
                'default' => 'sys_users:auth_profiles',
                'help' => 'Auth/profile checkpoint name used as upper bound (max legacy id already processed by Phase A).',
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
        $authCheckpointKey = (string)$args->getOption('auth-checkpoint-key');
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

        try {
            $legacy = $this->makeMysqlPdo($cfg['legacy']);
            $target = $this->makePgPdo($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connection failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }

        if (!$dryRun) {
            $this->ensureCheckpointTable($target);
            $this->ensureLegacyPasswordTable($target);
        }

        $lastId = $fromId > 0 ? $fromId : 0;
        // Resume cursor must apply in dry-run too.
        if ($fromId === 0 && $resume) {
            $cp = $this->getCheckpoint($target, $checkpointKey);
            if ($cp !== null && $cp > 0) {
                $lastId = $cp;
            }
        }

        // Upper bound for legacy id scan: highest legacy row that has a map entry (Phase A output).
        // Do NOT use sys_users:auth_profiles checkpoint here — after rollback that cursor can be lowered
        // while higher map rows still exist; the old checkpoint-as-ceiling would hide missing map rows below it.
        $authMaxLegacyId = 0;
        if (!$dryRun) {
            try {
                $mx = $target->query('SELECT COALESCE(MAX(legacy_user_id), 0) FROM public.migration_sys_users_map');
                $authMaxLegacyId = (int)$mx->fetchColumn();
            } catch (Throwable $e) {
                $authMaxLegacyId = 0;
            }
            if ($authMaxLegacyId === 0) {
                try {
                    $authCp = $this->getCheckpoint($target, $authCheckpointKey);
                    if ($authCp !== null && $authCp > 0) {
                        $authMaxLegacyId = $authCp;
                    }
                } catch (Throwable $e2) {
                    $authMaxLegacyId = 0;
                }
            }
        }

        $io->out(sprintf(
            'Phase B start (dry-run=%s, batch=%d, from-id=%d, checkpoint=%s)',
            $dryRun ? 'yes' : 'no',
            $batch,
            $lastId,
            $checkpointKey
        ));

        $processed = 0;
        $upserted = 0;
        $skipped = 0;
        $stopRequested = false;

        while (true) {
            $sql = "
                SELECT su.id, su.email, su.password
                FROM sys_users su
                WHERE COALESCE(su.deleted, 0) = 0
                  AND su.id > :last_id
                  AND su.email IS NOT NULL
                  AND TRIM(su.email) <> ''
                  AND su.email LIKE '%@%'
                  AND su.password IS NOT NULL
                  AND TRIM(su.password) <> ''
                  AND (:auth_max_id <= 0 OR su.id <= :auth_max_id)
                ORDER BY su.id ASC
                LIMIT :batch
            ";

            $stmt = $legacy->prepare($sql);
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':auth_max_id', $authMaxLegacyId, PDO::PARAM_INT);
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

                    $legacyUserId = (int)$row['id'];
                    $legacyHash = trim((string)$row['password']);
                    if ($legacyHash === '') {
                        $skipped++;
                        $io->out(sprintf(
                            'Skipping legacy_user_id=%d: empty password in legacy sys_users',
                            $legacyUserId
                        ));
                    } else {
                        $mapStmt = $target->prepare(
                            "SELECT auth_user_id
                             FROM public.migration_sys_users_map
                             WHERE legacy_user_id = :legacy_user_id
                             LIMIT 1"
                        );
                        $mapStmt->execute([':legacy_user_id' => $legacyUserId]);
                        $map = $mapStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$map || empty($map['auth_user_id'])) {
                            $skipped++;
                            $io->out(sprintf(
                                'Skipping legacy_user_id=%d: no migration_sys_users_map.auth_user_id (complete Phase A for this legacy id first)',
                                $legacyUserId
                            ));
                        } elseif ($dryRun) {
                            $upserted++;
                            $io->out(sprintf('Dry-run: would upsert legacy_password_migration for legacy_user_id=%d', $legacyUserId));
                        } else {
                            $authUserId = (string)$map['auth_user_id'];
                            $this->upsertLegacyPasswordMapping($target, $authUserId, $legacyHash, $legacyUserId);
                            $upserted++;
                        }
                    }

                    // Count as "examined" after hash / map / upsert or skip; then honor max-rows.
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
                $io->err("Phase B failed near legacy id={$lastId}: " . $e->getMessage());
                return static::CODE_ERROR;
            }

            $io->out("Processed={$processed}, upserted={$upserted}, skipped={$skipped}, checkpoint_id={$lastId}");
            if ($stopRequested) {
                break;
            }
        }

        $io->success("Phase B done. processed={$processed}, upserted={$upserted}, skipped={$skipped}, checkpoint_id={$lastId}");
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

    private function ensureLegacyPasswordTable(PDO $target): void
    {
        $target->exec("
            CREATE TABLE IF NOT EXISTS public.legacy_password_migration (
              id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id uuid NOT NULL UNIQUE REFERENCES auth.users(id) ON DELETE CASCADE,
              legacy_password_hash text NOT NULL,
              legacy_hash_algo text NOT NULL DEFAULT 'hmac_sha256_cake_salt_v1',
              needs_password_upgrade boolean NOT NULL DEFAULT false,
              migrated_at timestamptz NOT NULL DEFAULT now(),
              upgraded_at timestamptz,
              notes text
            )
        ");
    }

    private function upsertLegacyPasswordMapping(PDO $target, string $authUserId, string $legacyPasswordHash, int $legacyUserId): void
    {
        $stmt = $target->prepare(
            "INSERT INTO public.legacy_password_migration (
                user_id, legacy_password_hash, legacy_hash_algo, needs_password_upgrade, migrated_at, upgraded_at, notes
            ) VALUES (
                :user_id::uuid, :legacy_password_hash, 'hmac_sha256_cake_salt_v1', true, now(), null, :notes
            )
            ON CONFLICT (user_id) DO UPDATE SET
                legacy_password_hash = EXCLUDED.legacy_password_hash,
                legacy_hash_algo = EXCLUDED.legacy_hash_algo,
                needs_password_upgrade = true,
                upgraded_at = null,
                notes = EXCLUDED.notes"
        );

        $stmt->execute([
            ':user_id' => $authUserId,
            ':legacy_password_hash' => $legacyPasswordHash,
            ':notes' => 'migrated via MigrateSysUsersPasswordMapCommand; legacy_user_id=' . $legacyUserId,
        ]);
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
}

