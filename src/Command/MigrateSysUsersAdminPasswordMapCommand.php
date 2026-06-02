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
 * Phase B (admin): sys_users_admin.password -> public.legacy_password_migration
 *
 * Run after migrate_sys_users_admin_auth_profiles. Uses migration_sys_users_admin_map
 * to resolve auth.users.id. Same hash algo as sys_users Phase B (hmac_sha256_cake_salt_v1).
 *
 * If user_id already has a legacy_password_migration row (e.g. same email migrated via
 * sys_users Phase B), that row is left unchanged.
 *
 * Examples:
 *   bin/cake migrate_sys_users_admin_password_map --config config/migration_sys_users_LIVE.php --dry-run
 *   bin/cake migrate_sys_users_admin_password_map --config config/migration_sys_users_LIVE.php
 */
class MigrateSysUsersAdminPasswordMapCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Phase B admin: legacy admin password hashes -> legacy_password_migration.')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Absolute path to migration config file.',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
                'help' => 'Read/transform only. No writes on target DB.',
            ])
            ->addOption('from-id', [
                'default' => 0,
                'help' => 'Only process legacy admin id > from-id (0 = all).',
            ])
            ->addOption('log-file', [
                'default' => LOGS . 'migration_sys_users_admin_password_map.jsonl',
                'help' => 'JSONL report (overwritten each run).',
            ])
            ->addOption('skipped-file', [
                'default' => LOGS . 'migration_sys_users_admin_password_map_skipped.jsonl',
                'help' => 'JSONL skipped rows (overwritten each run).',
            ])
            ->addOption('overwrite-existing', [
                'boolean' => true,
                'default' => false,
                'help' => 'Replace existing legacy_password_migration row with sys_users_admin hash (use if login fails with legacy_hash_mismatch).',
            ])
            ->addOption('verify-email', [
                'default' => '',
                'help' => 'Test one login: email must match user_profiles (not legacy username). Requires --verify-password.',
            ])
            ->addOption('verify-password', [
                'default' => '',
                'help' => 'Plain password to test against stored hash (same HMAC as edge function).',
            ])
            ->addOption('legacy-salt', [
                'default' => '',
                'help' => 'CakePHP Security salt for verify (default: env LEGACY_PASSWORD_SALT or target.legacy_password_salt in config).',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $fromId = max(0, (int)$args->getOption('from-id'));
        $logFile = (string)$args->getOption('log-file');
        $skippedFile = (string)$args->getOption('skipped-file');
        $overwriteExisting = (bool)$args->getOption('overwrite-existing');
        $verifyEmail = strtolower(trim((string)$args->getOption('verify-email')));
        $verifyPassword = (string)$args->getOption('verify-password');

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

        $this->ensureAdminMapTable($target);
        $this->ensureLegacyPasswordTable($target);

        $legacySalt = trim((string)$args->getOption('legacy-salt'));
        if ($legacySalt === '') {
            $legacySalt = trim((string)($cfg['target']['legacy_password_salt'] ?? ''));
        }
        if ($legacySalt === '') {
            $legacySalt = trim((string)(getenv('LEGACY_PASSWORD_SALT') ?: ''));
        }

        if ($verifyEmail !== '' || $verifyPassword !== '') {
            return $this->runVerifyLegacyPassword($target, $verifyEmail, $verifyPassword, $legacySalt, $io);
        }

        $totalAdmin = $this->countAdmins($legacy);
        $mapRowCount = $this->countAdminMapRows($target);
        $rows = $this->fetchAdminsWithPassword($legacy, $fromId);
        $totalToProcess = count($rows);

        $io->out('=== sys_users_admin password map (Phase B) ===');
        $io->out(sprintf('Legacy admins (non-deleted): %d', $totalAdmin));
        $io->out(sprintf('migration_sys_users_admin_map rows: %d', $mapRowCount));
        $io->out(sprintf('Rows with password to process (id > %d): %d', $fromId, $totalToProcess));
        $io->out(sprintf('Dry-run: %s', $dryRun ? 'yes' : 'no'));
        $io->out(sprintf('Overwrite existing legacy_password_migration rows: %s', $overwriteExisting ? 'yes' : 'no'));

        if ($mapRowCount === 0) {
            $io->err(
                'migration_sys_users_admin_map is empty. Run Phase A live first: '
                . 'bin/cake migrate_sys_users_admin_auth_profiles --config <same-config>'
            );
            return static::CODE_ERROR;
        }

        if ($totalToProcess === 0) {
            $io->warning('No admin rows to process.');
            return static::CODE_SUCCESS;
        }

        $processed = 0;
        $upserted = 0;
        $skipped = 0;
        $alreadyHadLegacyRow = 0;
        $logRows = [];
        $skippedRows = [];
        $lastId = 0;

        if (!$dryRun) {
            $target->beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                $processed++;
                $lastId = (int)$row['id'];
                $legacyAdminId = $lastId;
                $legacyHash = trim((string)$row['password']);
                $email = strtolower(trim((string)$row['username']));

                $io->out(sprintf('[%d/%d] legacy_admin_id=%d email=%s', $processed, $totalToProcess, $legacyAdminId, $email));

                if ($legacyHash === '') {
                    $skipped++;
                    $skippedRows[] = $this->buildSkipEntry($legacyAdminId, $email, 'empty_legacy_password');
                    continue;
                }

                $mapStmt = $target->prepare(
                    'SELECT auth_user_id::text FROM public.migration_sys_users_admin_map
                     WHERE legacy_admin_id = :legacy_admin_id LIMIT 1'
                );
                $mapStmt->execute([':legacy_admin_id' => $legacyAdminId]);
                $map = $mapStmt->fetch(PDO::FETCH_ASSOC);

                if (!$map || empty($map['auth_user_id'])) {
                    $skipped++;
                    $skippedRows[] = $this->buildSkipEntry(
                        $legacyAdminId,
                        $email,
                        'no_admin_map_run_auth_profiles_first'
                    );
                    $io->warning('  skip: no migration_sys_users_admin_map row');
                    continue;
                }

                $authUserId = (string)$map['auth_user_id'];

                $hadLegacyRow = $this->legacyPasswordRowExists($target, $authUserId);
                if ($hadLegacyRow && !$overwriteExisting) {
                    $alreadyHadLegacyRow++;
                    $skippedRows[] = $this->buildSkipEntry(
                        $legacyAdminId,
                        $email,
                        'legacy_password_migration_already_exists',
                        ['auth_user_id' => $authUserId]
                    );
                    $io->warning('  skip: row exists (likely sys_users Phase B) — use --overwrite-existing to store admin hash');
                    continue;
                }

                if ($dryRun) {
                    $upserted++;
                    $logRows[] = [
                        'ts' => gmdate('c'),
                        'event' => $hadLegacyRow ? 'dry_run_would_overwrite' : 'dry_run_would_upsert',
                        'legacy_admin_id' => $legacyAdminId,
                        'email' => $email,
                        'auth_user_id' => $authUserId,
                    ];
                    continue;
                }

                $this->upsertLegacyPasswordMapping($target, $authUserId, $legacyHash, $legacyAdminId, $overwriteExisting && $hadLegacyRow);
                $upserted++;
                $logRows[] = [
                    'ts' => gmdate('c'),
                    'event' => 'upserted',
                    'legacy_admin_id' => $legacyAdminId,
                    'email' => $email,
                    'auth_user_id' => $authUserId,
                ];
                $io->success('  upserted legacy_password_migration');
            }

            if (!$dryRun) {
                $target->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $target->inTransaction()) {
                $target->rollBack();
            }
            $io->err("Admin password map failed near legacy_admin_id={$lastId}: " . $e->getMessage());
            return static::CODE_ERROR;
        }

        $this->writeJsonlReport($logFile, $logRows);
        $this->writeJsonlReport($skippedFile, $skippedRows);

        $io->out('');
        $io->out('=== Summary ===');
        $io->out(sprintf('Processed:                      %d', $processed));
        $io->out(sprintf('Upserted legacy_password_migration: %d', $upserted));
        $io->out(sprintf('Already had legacy row (kept):  %d', $alreadyHadLegacyRow));
        $io->out(sprintf('Skipped (other):                %d', $skipped));
        if ($logFile !== '') {
            $io->out("Log: {$logFile}");
        }

        $io->success(sprintf(
            'Done. upserted=%d, already_had_row=%d, skipped=%d',
            $upserted,
            $alreadyHadLegacyRow,
            $skipped
        ));

        return static::CODE_SUCCESS;
    }

    private function countAdmins(PDO $legacy): int
    {
        $stmt = $legacy->query('SELECT COUNT(*) FROM sys_users_admin WHERE COALESCE(deleted, 0) = 0');
        if ($stmt === false) {
            return 0;
        }

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAdminsWithPassword(PDO $legacy, int $fromId): array
    {
        $stmt = $legacy->prepare("
            SELECT id, username, password
            FROM sys_users_admin
            WHERE COALESCE(deleted, 0) = 0
              AND id > :from_id
              AND username IS NOT NULL
              AND TRIM(username) <> ''
              AND password IS NOT NULL
              AND TRIM(password) <> ''
            ORDER BY id ASC
        ");
        $stmt->execute([':from_id' => $fromId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function countAdminMapRows(PDO $target): int
    {
        try {
            return (int)$target->query('SELECT COUNT(*) FROM public.migration_sys_users_admin_map')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function ensureAdminMapTable(PDO $target): void
    {
        $target->exec("
            CREATE TABLE IF NOT EXISTS public.migration_sys_users_admin_map (
              legacy_admin_id bigint PRIMARY KEY,
              legacy_uid text,
              legacy_username text,
              legacy_user_type text,
              auth_user_id uuid,
              user_profile_id uuid,
              mapped_app_role text,
              duplicate_of_sys_users boolean NOT NULL DEFAULT false,
              legacy_sys_user_id bigint,
              duplicate_sources jsonb,
              migrated_at timestamptz NOT NULL DEFAULT now()
            )
        ");
        $target->exec("CREATE UNIQUE INDEX IF NOT EXISTS migration_sys_users_admin_map_auth_user_id
            ON public.migration_sys_users_admin_map (auth_user_id) WHERE auth_user_id IS NOT NULL");
    }

    private function legacyPasswordRowExists(PDO $target, string $authUserId): bool
    {
        $stmt = $target->prepare(
            'SELECT 1 FROM public.legacy_password_migration WHERE user_id = :user_id::uuid LIMIT 1'
        );
        $stmt->execute([':user_id' => $authUserId]);
        return (bool)$stmt->fetchColumn();
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

    private function upsertLegacyPasswordMapping(
        PDO $target,
        string $authUserId,
        string $legacyPasswordHash,
        int $legacyAdminId,
        bool $overwrite = false
    ): void {
        $conflict = $overwrite
            ? "ON CONFLICT (user_id) DO UPDATE SET
                legacy_password_hash = EXCLUDED.legacy_password_hash,
                legacy_hash_algo = EXCLUDED.legacy_hash_algo,
                needs_password_upgrade = true,
                upgraded_at = null,
                notes = EXCLUDED.notes"
            : 'ON CONFLICT (user_id) DO NOTHING';

        $stmt = $target->prepare(
            "INSERT INTO public.legacy_password_migration (
                user_id, legacy_password_hash, legacy_hash_algo, needs_password_upgrade, migrated_at, upgraded_at, notes
            ) VALUES (
                :user_id::uuid, :legacy_password_hash, 'hmac_sha256_cake_salt_v1', true, now(), null, :notes
            )
            {$conflict}"
        );

        $stmt->execute([
            ':user_id' => $authUserId,
            ':legacy_password_hash' => $legacyPasswordHash,
            ':notes' => 'migrated via MigrateSysUsersAdminPasswordMapCommand; legacy_admin_id=' . $legacyAdminId,
        ]);
    }

    private function runVerifyLegacyPassword(
        PDO $target,
        string $email,
        string $plainPassword,
        string $legacySalt,
        ConsoleIo $io
    ): int {
        if ($email === '' || strpos($email, '@') === false || $plainPassword === '') {
            $io->err('Provide --verify-email (must contain @) and --verify-password.');
            return static::CODE_ERROR;
        }
        if ($legacySalt === '') {
            $io->err('Missing legacy salt. Set LEGACY_PASSWORD_SALT env, target.legacy_password_salt in config, or --legacy-salt.');
            return static::CODE_ERROR;
        }

        $profileStmt = $target->prepare(
            "SELECT user_id::text, email FROM public.user_profiles WHERE lower(trim(email)) = :email LIMIT 1"
        );
        $profileStmt->execute([':email' => $email]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            $io->err("No user_profiles row for email={$email}. Run Phase A or sign in with profile email, not legacy username.");
            return static::CODE_ERROR;
        }

        $authUserId = (string)$profile['user_id'];
        $lpmStmt = $target->prepare(
            "SELECT legacy_password_hash, needs_password_upgrade, notes
             FROM public.legacy_password_migration WHERE user_id = :user_id::uuid LIMIT 1"
        );
        $lpmStmt->execute([':user_id' => $authUserId]);
        $lpm = $lpmStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lpm) {
            $io->err("No legacy_password_migration row for user_id={$authUserId}. Run Phase B.");
            return static::CODE_ERROR;
        }

        $calculated = strtolower(hash_hmac('sha256', $plainPassword, $legacySalt));
        $stored = strtolower(trim((string)$lpm['legacy_password_hash']));
        $match = hash_equals($stored, $calculated);
        $needsUpgrade = filter_var($lpm['needs_password_upgrade'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $io->out("email:                  {$email}");
        $io->out("auth user_id:           {$authUserId}");
        $io->out('needs_password_upgrade: ' . ($needsUpgrade ? 'true' : 'false'));
        $io->out('notes:                  ' . (string)($lpm['notes'] ?? ''));
        $io->out('hash match:             ' . ($match ? 'YES' : 'NO'));

        if (!$needsUpgrade) {
            $io->warning('needs_password_upgrade is false — edge function returns not_eligible_for_upgrade.');
        }
        if (!$match) {
            $io->warning('Hash mismatch — wrong password, wrong LEGACY_PASSWORD_SALT, or row has sys_users hash not admin hash. Try --overwrite-existing and re-run Phase B.');
        }

        return $match && $needsUpgrade ? static::CODE_SUCCESS : static::CODE_ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSkipEntry(int $legacyAdminId, string $email, string $reason, array $extra = []): array
    {
        return array_merge([
            'ts' => gmdate('c'),
            'legacy_admin_id' => $legacyAdminId,
            'email' => $email,
            'skip_reason' => $reason,
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeJsonlReport(string $path, array $rows): void
    {
        $file = trim($path);
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, '');
        foreach ($rows as $row) {
            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line)) {
                @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
            }
        }
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
