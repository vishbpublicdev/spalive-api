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
 * One-shot migration: legacy sys_users_admin (~90 rows) -> Supabase auth + user_profiles +
 * migration_sys_users_admin_map.
 *
 * Does NOT populate public.legacy_password_migration — run migrate_sys_users_admin_password_map
 * afterward (same legacy-password login flow as sys_users Phase B).
 *
 * Loads the full legacy count up front, processes every row in a single run (one DB transaction),
 * and writes fresh JSONL/CSV reports at the end. Users already present in Supabase are listed in
 * --existing-users-file / --existing-users-csv.
 *
 * Examples:
 *   bin/cake migrate_sys_users_admin_auth_profiles --config config/migration_sys_users_LIVE.php --dry-run
 *   bin/cake migrate_sys_users_admin_auth_profiles --config config/migration_sys_users_LIVE.php
 *   bin/cake migrate_sys_users_admin_auth_profiles --duplicates-only --config config/migration_sys_users.php
 */
class MigrateSysUsersAdminAuthProfilesCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('One-shot migrate sys_users_admin -> auth + profiles + admin map (~90 rows).')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Absolute path to migration config file (same legacy/target as sys_users migration).',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
                'help' => 'Read/transform only. No writes on target DB.',
            ])
            ->addOption('duplicates-only', [
                'boolean' => true,
                'default' => false,
                'help' => 'Scan legacy admin users and write duplicate-email report only (no migration).',
            ])
            ->addOption('from-id', [
                'default' => 0,
                'help' => 'Only process legacy admin id > from-id (0 = all rows).',
            ])
            ->addOption('log-file', [
                'default' => LOGS . 'migration_sys_users_admin_auth_profiles.jsonl',
                'help' => 'JSONL log of created/linked rows (overwritten each run).',
            ])
            ->addOption('existing-users-file', [
                'default' => LOGS . 'migration_sys_users_admin_existing_supabase.jsonl',
                'help' => 'JSONL list of admins whose email already exists in Supabase (overwritten each run).',
            ])
            ->addOption('existing-users-csv', [
                'default' => LOGS . 'migration_sys_users_admin_existing_supabase.csv',
                'help' => 'CSV list of existing Supabase users (empty = skip CSV).',
            ])
            ->addOption('duplicates-file', [
                'default' => LOGS . 'migration_sys_users_admin_duplicates.jsonl',
                'help' => 'JSONL duplicate-email audit rows (overwritten each run).',
            ])
            ->addOption('duplicates-csv', [
                'default' => LOGS . 'migration_sys_users_admin_duplicates.csv',
                'help' => 'CSV duplicate-email audit (empty = skip CSV).',
            ])
            ->addOption('skipped-file', [
                'default' => LOGS . 'migration_sys_users_admin_skipped.jsonl',
                'help' => 'JSONL skipped users (overwritten each run).',
            ])
            ->addOption('skipped-csv', [
                'default' => LOGS . 'migration_sys_users_admin_skipped.csv',
                'help' => 'CSV skipped users (empty = skip CSV).',
            ])
            ->addOption('link-duplicates', [
                'boolean' => true,
                'default' => true,
                'help' => 'When email exists in Supabase/Phase-A, insert admin map pointing at existing auth user.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $duplicatesOnly = (bool)$args->getOption('duplicates-only');
        $fromId = max(0, (int)$args->getOption('from-id'));
        $logFile = (string)$args->getOption('log-file');
        $existingUsersFile = (string)$args->getOption('existing-users-file');
        $existingUsersCsv = trim((string)$args->getOption('existing-users-csv'));
        $duplicatesFile = (string)$args->getOption('duplicates-file');
        $duplicatesCsv = trim((string)$args->getOption('duplicates-csv'));
        $skippedFile = (string)$args->getOption('skipped-file');
        $skippedCsv = trim((string)$args->getOption('skipped-csv'));
        $linkDuplicates = (bool)$args->getOption('link-duplicates');

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

        if (!$dryRun && !$duplicatesOnly && ($supabaseApiUrl === '' || $supabaseServiceRoleKey === '')) {
            $io->err('Missing Supabase Admin API config. Set target.api_url + target.service_role_key.');
            return static::CODE_ERROR;
        }

        if (!$dryRun && !$duplicatesOnly) {
            $this->ensureAdminMapTable($target);
        }

        if ($duplicatesOnly) {
            return $this->runDuplicatesReport($legacy, $target, $duplicatesFile, $duplicatesCsv, $io);
        }

        $totalLegacy = $this->countAdmins($legacy);
        $rows = $this->fetchMigratableAdmins($legacy, $fromId);
        $totalToProcess = count($rows);

        $io->out('=== sys_users_admin one-shot migration ===');
        $io->out(sprintf('Legacy total (non-deleted): %d', $totalLegacy));
        $io->out(sprintf('Rows to process (id > %d, valid username filter): %d', $fromId, $totalToProcess));
        $io->out(sprintf(
            'Mode: dry-run=%s, link-existing=%s',
            $dryRun ? 'yes' : 'no',
            $linkDuplicates ? 'yes' : 'no'
        ));

        if ($totalToProcess === 0) {
            $io->warning('No admin rows to process.');
            return static::CODE_SUCCESS;
        }

        $processed = 0;
        $createdNew = 0;
        $linkedExisting = 0;
        $skipped = 0;
        $alreadyMapped = 0;
        $duplicateRows = [];
        $existingUsersRows = [];
        $skippedRows = [];
        $successLogRows = [];
        $lastId = 0;

        if (!$dryRun) {
            $target->beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                $processed++;
                $lastId = (int)$row['id'];
                $username = $this->normalizeEmailForAuth((string)$row['username']);
                $io->out(sprintf('[%d/%d] legacy_admin_id=%d email=%s', $processed, $totalToProcess, $lastId, $username));

                if ($username === '' || strpos($username, '@') === false) {
                    $skipped++;
                    $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'missing_email');
                    continue;
                }
                if (!$this->isRFC5322LooseEmail($username)) {
                    $skipped++;
                    $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'invalid_email');
                    $io->warning(sprintf('  skip: invalid email %s', $username));
                    continue;
                }

                if (!$dryRun && $this->adminMapExists($target, $lastId)) {
                    $alreadyMapped++;
                    $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'already_migrated');
                    $io->out('  skip: already in migration_sys_users_admin_map');
                    continue;
                }

                $intendedAppRole = $this->mapAdminUserType((string)$row['user_type']);
                $dup = $this->resolveDuplicate($legacy, $target, $username);
                if ($dup !== null) {
                    $duplicateRows[] = $this->buildDuplicateReportRow($row, $username, $dup);
                    $existingUsersRows[] = $this->buildExistingUserRow(
                        $row,
                        $username,
                        $dup,
                        'pre_check',
                        null
                    );

                    $roleConflict = $this->hasRoleConflict(
                        $intendedAppRole,
                        isset($dup['mapped_app_role']) ? (string)$dup['mapped_app_role'] : null
                    );

                    if ($roleConflict) {
                        $skipped++;
                        $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'existing_role_conflict', [
                            'existing_mapped_app_role' => $dup['mapped_app_role'] ?? null,
                            'intended_admin_app_role' => $intendedAppRole,
                            'duplicate_sources' => $dup['sources'],
                            'auth_user_id' => $dup['auth_user_id'] ?? null,
                            'legacy_sys_user_id' => $dup['legacy_sys_user_id'] ?? null,
                        ]);
                        $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'skipped_role_conflict';
                        $io->warning('  existing Supabase user: role conflict — not linked');
                        continue;
                    }

                    if ($dryRun) {
                        $linkedExisting++;
                        $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'dry_run_would_link';
                        $io->out('  existing Supabase user: would link admin map');
                        continue;
                    }

                    if ($linkDuplicates && !empty($dup['auth_user_id'])) {
                        $this->upsertAdminMap($target, $row, $username, (string)$dup['auth_user_id'], $dup, true);
                        $linkedExisting++;
                        $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'linked_admin_map';
                        $successLogRows[] = [
                            'ts' => gmdate('c'),
                            'event' => 'linked_existing_supabase_user',
                            'legacy_admin_id' => $lastId,
                            'email' => $username,
                            'auth_user_id' => $dup['auth_user_id'],
                            'duplicate_sources' => $dup['sources'],
                        ];
                        $io->success('  linked to existing Supabase auth user');
                        continue;
                    }

                    $skipped++;
                    $skippedRows[] = $this->buildSkippedUserEntry(
                        $row,
                        $username,
                        empty($dup['auth_user_id']) ? 'duplicate_email_no_auth_user' : 'duplicate_email_skipped',
                        [
                            'existing_mapped_app_role' => $dup['mapped_app_role'] ?? null,
                            'intended_admin_app_role' => $intendedAppRole,
                            'duplicate_sources' => $dup['sources'],
                            'auth_user_id' => $dup['auth_user_id'] ?? null,
                            'legacy_sys_user_id' => $dup['legacy_sys_user_id'] ?? null,
                        ]
                    );
                    $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'skipped_no_link';
                    continue;
                }

                $appRole = $intendedAppRole;
                $isSystemAdmin = $this->isSystemAdminType((string)$row['user_type']);
                $staffRole = $this->mapStaffRole((string)$row['user_type']);
                $isActive = ((int)$row['active'] === 1);
                $fullName = $this->asNullableText($row['name'] ?? null);
                $createdAt = $this->toTimestamp($row['created'] ?? null);
                $updatedAt = $this->toTimestamp($row['modified'] ?? null) ?: $createdAt;
                $stateCodes = $this->loadAdminStateCodes($legacy, $lastId);

                if ($dryRun) {
                    $createdNew++;
                    $io->out('  dry-run: would create auth user + profile + admin map');
                    continue;
                }

                $legacyMeta = [
                    'legacy_admin_id' => $lastId,
                    'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
                    'legacy_user_type' => $this->asNullableText($row['user_type'] ?? null),
                    'legacy_organization_id' => (int)($row['organization_id'] ?? 0),
                    'source' => 'sys_users_admin',
                ];

                $createResult = $this->createAuthUserViaApi(
                    $supabaseApiUrl,
                    $supabaseServiceRoleKey,
                    $username,
                    $defaultAuthPassword,
                    $legacyMeta
                );

                if ($createResult === 'exists') {
                    $dup = $this->resolveDuplicate($legacy, $target, $username);
                    if ($dup !== null) {
                        $duplicateRows[] = $this->buildDuplicateReportRow($row, $username, $dup);
                        $existingUsersRows[] = $this->buildExistingUserRow(
                            $row,
                            $username,
                            $dup,
                            'api_create_already_exists',
                            null
                        );

                        $roleConflict = $this->hasRoleConflict(
                            $appRole,
                            isset($dup['mapped_app_role']) ? (string)$dup['mapped_app_role'] : null
                        );
                        if ($roleConflict) {
                            $skipped++;
                            $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'existing_role_conflict', [
                                'existing_mapped_app_role' => $dup['mapped_app_role'] ?? null,
                                'intended_admin_app_role' => $appRole,
                                'duplicate_sources' => $dup['sources'],
                                'auth_user_id' => $dup['auth_user_id'] ?? null,
                                'legacy_sys_user_id' => $dup['legacy_sys_user_id'] ?? null,
                            ]);
                            $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'skipped_role_conflict';
                            $io->warning('  Supabase API: user exists — role conflict');
                            continue;
                        }
                        if (!empty($dup['auth_user_id'])) {
                            $this->upsertAdminMap($target, $row, $username, (string)$dup['auth_user_id'], $dup, true);
                            $linkedExisting++;
                            $existingUsersRows[count($existingUsersRows) - 1]['outcome'] = 'linked_admin_map';
                            $successLogRows[] = [
                                'ts' => gmdate('c'),
                                'event' => 'linked_after_api_exists',
                                'legacy_admin_id' => $lastId,
                                'email' => $username,
                                'auth_user_id' => $dup['auth_user_id'],
                            ];
                            $io->success('  Supabase API: user exists — linked admin map');
                            continue;
                        }
                    }
                    $skipped++;
                    $skippedRows[] = $this->buildSkippedUserEntry($row, $username, 'api_exists_unresolved');
                    continue;
                }

                $authUserId = $this->requireAuthUserId($target, $username);
                $this->patchAuthUser($target, $authUserId, $isActive, $updatedAt);
                $this->ensureAuthIdentity($target, $authUserId, $username, $createdAt, $updatedAt);
                $profileId = $this->ensureAdminProfile(
                    $target,
                    $authUserId,
                    $username,
                    $fullName,
                    $appRole,
                    $staffRole,
                    $isSystemAdmin,
                    $isActive,
                    $stateCodes,
                    $createdAt,
                    $updatedAt
                );
                $this->upsertAdminMap($target, $row, $username, $authUserId, [
                    'user_profile_id' => $profileId,
                    'mapped_app_role' => $appRole,
                    'duplicate_of_sys_users' => false,
                    'legacy_sys_user_id' => null,
                    'sources' => [],
                ], false);
                $createdNew++;
                $successLogRows[] = [
                    'ts' => gmdate('c'),
                    'event' => 'created_new_supabase_user',
                    'legacy_admin_id' => $lastId,
                    'email' => $username,
                    'auth_user_id' => $authUserId,
                    'user_profile_id' => $profileId,
                ];
                $io->success('  created new Supabase auth user + profile');
            }

            if (!$dryRun) {
                $target->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $target->inTransaction()) {
                $target->rollBack();
            }
            $io->err("Admin migration failed near legacy_admin_id={$lastId}: " . $e->getMessage());
            return static::CODE_ERROR;
        }

        $this->writeJsonlReport($logFile, $successLogRows);
        $this->writeJsonlReport($existingUsersFile, $existingUsersRows);
        $this->writeExistingUsersCsv($existingUsersCsv, $existingUsersRows);
        $this->writeJsonlReport($duplicatesFile, $duplicateRows);
        $this->writeDuplicatesCsv($duplicatesCsv, $duplicateRows);
        $this->writeJsonlReport($skippedFile, $skippedRows);
        $this->writeSkippedCsv($skippedCsv, $skippedRows);

        $io->out('');
        $io->out('=== Summary ===');
        $io->out(sprintf('Legacy total (non-deleted):     %d', $totalLegacy));
        $io->out(sprintf('Processed this run:             %d', $processed));
        $io->out(sprintf('New Supabase users created:     %d', $createdNew));
        $io->out(sprintf('Linked to existing Supabase:    %d', $linkedExisting));
        $io->out(sprintf('Already in admin map (skipped): %d', $alreadyMapped));
        $io->out(sprintf('Skipped (errors/conflicts):     %d', $skipped));
        $io->out(sprintf('Existing-in-Supabase list:      %d', count($existingUsersRows)));
        if ($existingUsersFile !== '') {
            $io->out("  JSONL: {$existingUsersFile}");
        }
        if ($existingUsersCsv !== '') {
            $io->out("  CSV:   {$existingUsersCsv}");
        }
        if ($skippedFile !== '' && $skippedRows !== []) {
            $io->out("Skipped list: {$skippedFile}");
        }

        $io->success(sprintf(
            'Done. created=%d, linked_existing=%d, already_mapped=%d, skipped=%d, existing_supabase=%d',
            $createdNew,
            $linkedExisting,
            $alreadyMapped,
            $skipped,
            count($existingUsersRows)
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
    private function fetchMigratableAdmins(PDO $legacy, int $fromId): array
    {
        $stmt = $legacy->prepare("
            SELECT id, uid, username, name, user_type, active, organization_id, deleted, created, modified
            FROM sys_users_admin
            WHERE COALESCE(deleted, 0) = 0
              AND id > :from_id
              AND username IS NOT NULL
              AND TRIM(username) <> ''
            ORDER BY id ASC
        ");
        $stmt->execute([':from_id' => $fromId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $dup
     * @return array<string, mixed>
     */
    private function buildExistingUserRow(
        array $row,
        string $email,
        array $dup,
        string $detectedVia,
        ?string $outcome
    ): array {
        return [
            'ts' => gmdate('c'),
            'legacy_admin_id' => (int)$row['id'],
            'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
            'email' => $email,
            'legacy_username' => (string)$row['username'],
            'legacy_user_type' => (string)$row['user_type'],
            'intended_admin_app_role' => $this->mapAdminUserType((string)$row['user_type']),
            'existing_mapped_app_role' => $dup['mapped_app_role'] ?? null,
            'auth_user_id' => $dup['auth_user_id'] ?? null,
            'user_profile_id' => $dup['user_profile_id'] ?? null,
            'legacy_sys_user_id' => $dup['legacy_sys_user_id'] ?? null,
            'supabase_sources' => $dup['sources'] ?? [],
            'detected_via' => $detectedVia,
            'outcome' => $outcome,
        ];
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
            $this->appendLogLine($file, $row);
        }
    }

    /**
     * @param list<array<string, mixed>> $existing
     */
    private function writeExistingUsersCsv(string $csvPath, array $existing): void
    {
        if (trim($csvPath) === '' || $existing === []) {
            return;
        }
        $dir = dirname($csvPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($csvPath, 'wb');
        if ($fh === false) {
            return;
        }
        fputcsv($fh, [
            'legacy_admin_id',
            'legacy_uid',
            'email',
            'legacy_user_type',
            'intended_admin_app_role',
            'existing_mapped_app_role',
            'auth_user_id',
            'user_profile_id',
            'legacy_sys_user_id',
            'supabase_sources',
            'detected_via',
            'outcome',
        ]);
        foreach ($existing as $e) {
            fputcsv($fh, [
                $e['legacy_admin_id'] ?? '',
                $e['legacy_uid'] ?? '',
                $e['email'] ?? '',
                $e['legacy_user_type'] ?? '',
                $e['intended_admin_app_role'] ?? '',
                $e['existing_mapped_app_role'] ?? '',
                $e['auth_user_id'] ?? '',
                $e['user_profile_id'] ?? '',
                $e['legacy_sys_user_id'] ?? '',
                is_array($e['supabase_sources'] ?? null) ? implode('|', $e['supabase_sources']) : '',
                $e['detected_via'] ?? '',
                $e['outcome'] ?? '',
            ]);
        }
        fclose($fh);
    }

    private function runDuplicatesReport(PDO $legacy, PDO $target, string $duplicatesFile, string $duplicatesCsv, ConsoleIo $io): int
    {
        $total = $this->countAdmins($legacy);
        $io->out(sprintf('Legacy sys_users_admin total (non-deleted): %d', $total));
        $io->out('Scanning for emails already present in Supabase / Phase-A sys_users...');

        $rows = $this->fetchAllAdmins($legacy);
        $duplicates = [];
        foreach ($rows as $row) {
            $username = $this->normalizeEmailForAuth((string)$row['username']);
            if ($username === '' || strpos($username, '@') === false || !$this->isRFC5322LooseEmail($username)) {
                continue;
            }
            $dup = $this->resolveDuplicate($legacy, $target, $username);
            if ($dup !== null) {
                $duplicates[] = $this->buildDuplicateReportRow($row, $username, $dup);
            }
        }

        $path = trim($duplicatesFile);
        if ($path !== '') {
            @file_put_contents($path, '');
            foreach ($duplicates as $entry) {
                $this->appendLogLine($path, $entry);
            }
        }
        $this->writeDuplicatesCsv($duplicatesCsv, $duplicates);

        $io->out(sprintf('Total admin rows scanned: %d', count($rows)));
        $io->out(sprintf('Duplicate emails found: %d', count($duplicates)));
        if ($path !== '') {
            $io->out("JSONL report: {$path}");
        }
        if ($duplicatesCsv !== '') {
            $io->out("CSV report: {$duplicatesCsv}");
        }

        if ($duplicates !== []) {
            $io->out('');
            $io->out('legacy_admin_id | email | admin_type | sources | legacy_sys_user_id | auth_user_id');
            foreach ($duplicates as $d) {
                $io->out(sprintf(
                    '%d | %s | %s | %s | %s | %s',
                    (int)$d['legacy_admin_id'],
                    (string)$d['email'],
                    (string)$d['legacy_user_type'],
                    implode(',', (array)$d['duplicate_sources']),
                    $d['legacy_sys_user_id'] === null ? '-' : (string)$d['legacy_sys_user_id'],
                    $d['auth_user_id'] === null ? '-' : (string)$d['auth_user_id']
                ));
            }
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllAdmins(PDO $legacy): array
    {
        $stmt = $legacy->query("
            SELECT id, uid, username, name, user_type, active, organization_id, deleted, created, modified
            FROM sys_users_admin
            WHERE COALESCE(deleted, 0) = 0
            ORDER BY id ASC
        ");
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDuplicate(PDO $legacy, PDO $target, string $email): ?array
    {
        $sources = [];
        $authUserId = null;
        $userProfileId = null;
        $legacySysUserId = null;
        $mappedAppRole = null;

        $mapStmt = $target->prepare(
            "SELECT legacy_user_id, auth_user_id::text, user_profile_id::text, mapped_app_role
             FROM public.migration_sys_users_map
             WHERE lower(trim(legacy_email)) = :email
             LIMIT 1"
        );
        $mapStmt->execute([':email' => $email]);
        $mapRow = $mapStmt->fetch(PDO::FETCH_ASSOC);
        if ($mapRow) {
            $sources[] = 'migration_sys_users_map';
            $authUserId = (string)$mapRow['auth_user_id'];
            $userProfileId = $mapRow['user_profile_id'] !== null ? (string)$mapRow['user_profile_id'] : null;
            $legacySysUserId = (int)$mapRow['legacy_user_id'];
            $mappedAppRole = $mapRow['mapped_app_role'] !== null ? (string)$mapRow['mapped_app_role'] : null;
        }

        if ($authUserId === null) {
            $authStmt = $target->prepare("SELECT id::text FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1");
            $authStmt->execute([':email' => $email]);
            $authRow = $authStmt->fetch(PDO::FETCH_ASSOC);
            if ($authRow) {
                $sources[] = 'auth.users';
                $authUserId = (string)$authRow['id'];
            }
        }

        $profStmt = $target->prepare(
            "SELECT id::text, user_id::text, app_role
             FROM public.user_profiles
             WHERE lower(trim(email)) = :email
             LIMIT 1"
        );
        $profStmt->execute([':email' => $email]);
        $profRow = $profStmt->fetch(PDO::FETCH_ASSOC);
        if ($profRow) {
            $sources[] = 'user_profiles';
            if ($authUserId === null) {
                $authUserId = (string)$profRow['user_id'];
            }
            $userProfileId = (string)$profRow['id'];
            if ($mappedAppRole === null && isset($profRow['app_role'])) {
                $mappedAppRole = (string)$profRow['app_role'];
            }
        }

        $legacyUserStmt = $legacy->prepare(
            "SELECT id, type FROM sys_users
             WHERE COALESCE(deleted, 0) = 0 AND lower(trim(email)) = :email
             LIMIT 1"
        );
        $legacyUserStmt->execute([':email' => $email]);
        $legacyUserRow = $legacyUserStmt->fetch(PDO::FETCH_ASSOC);
        if ($legacyUserRow) {
            $sources[] = 'sys_users';
            $legacySysUserId = (int)$legacyUserRow['id'];
            if ($mappedAppRole === null) {
                $mappedAppRole = (string)$legacyUserRow['type'];
            }
        }

        if ($sources === []) {
            return null;
        }

        return [
            'auth_user_id' => $authUserId,
            'user_profile_id' => $userProfileId,
            'legacy_sys_user_id' => $legacySysUserId,
            'mapped_app_role' => $mappedAppRole,
            'sources' => array_values(array_unique($sources)),
            'duplicate_of_sys_users' => in_array('sys_users', $sources, true)
                || in_array('migration_sys_users_map', $sources, true),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $dup
     * @return array<string, mixed>
     */
    private function buildDuplicateReportRow(array $row, string $email, array $dup): array
    {
        return [
            'ts' => gmdate('c'),
            'legacy_admin_id' => (int)$row['id'],
            'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
            'email' => $email,
            'legacy_username' => (string)$row['username'],
            'legacy_user_type' => (string)$row['user_type'],
            'legacy_admin_active' => (int)$row['active'] === 1,
            'duplicate_sources' => $dup['sources'],
            'auth_user_id' => $dup['auth_user_id'],
            'user_profile_id' => $dup['user_profile_id'],
            'legacy_sys_user_id' => $dup['legacy_sys_user_id'],
            'existing_mapped_app_role' => $dup['mapped_app_role'],
            'intended_admin_app_role' => $this->mapAdminUserType((string)$row['user_type']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     */
    private function upsertAdminMap(
        PDO $target,
        array $row,
        string $email,
        string $authUserId,
        array $meta,
        bool $isDuplicate
    ): void {
        $stmt = $target->prepare(
            "INSERT INTO public.migration_sys_users_admin_map (
                legacy_admin_id, legacy_uid, legacy_username, legacy_user_type,
                auth_user_id, user_profile_id, mapped_app_role,
                duplicate_of_sys_users, legacy_sys_user_id, duplicate_sources, migrated_at
            ) VALUES (
                :legacy_admin_id, :legacy_uid, :legacy_username, :legacy_user_type,
                :auth_user_id::uuid, :user_profile_id::uuid, :mapped_app_role,
                :duplicate_of_sys_users::boolean, :legacy_sys_user_id, :duplicate_sources::jsonb, now()
            )
            ON CONFLICT (legacy_admin_id) DO UPDATE SET
                legacy_uid = EXCLUDED.legacy_uid,
                legacy_username = EXCLUDED.legacy_username,
                legacy_user_type = EXCLUDED.legacy_user_type,
                auth_user_id = EXCLUDED.auth_user_id,
                user_profile_id = COALESCE(EXCLUDED.user_profile_id, migration_sys_users_admin_map.user_profile_id),
                mapped_app_role = EXCLUDED.mapped_app_role,
                duplicate_of_sys_users = EXCLUDED.duplicate_of_sys_users,
                legacy_sys_user_id = EXCLUDED.legacy_sys_user_id,
                duplicate_sources = EXCLUDED.duplicate_sources,
                migrated_at = EXCLUDED.migrated_at"
        );
        $stmt->execute([
            ':legacy_admin_id' => (int)$row['id'],
            ':legacy_uid' => $this->asNullableText($row['uid'] ?? null),
            ':legacy_username' => $email,
            ':legacy_user_type' => $this->asNullableText($row['user_type'] ?? null),
            ':auth_user_id' => $authUserId,
            ':user_profile_id' => $meta['user_profile_id'] ?? null,
            ':mapped_app_role' => $meta['mapped_app_role'] ?? $this->mapAdminUserType((string)$row['user_type']),
            ':duplicate_of_sys_users' => $isDuplicate ? 'true' : 'false',
            ':legacy_sys_user_id' => $meta['legacy_sys_user_id'] ?? null,
            ':duplicate_sources' => json_encode($meta['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
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

    private function adminMapExists(PDO $target, int $legacyAdminId): bool
    {
        $stmt = $target->prepare('SELECT 1 FROM public.migration_sys_users_admin_map WHERE legacy_admin_id = :id LIMIT 1');
        $stmt->execute([':id' => $legacyAdminId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function loadAdminStateCodes(PDO $legacy, int $adminId): array
    {
        try {
            $stmt = $legacy->prepare("
                SELECT COALESCE(cs.abv, cs.name, CAST(suas.state_id AS CHAR)) AS state_code
                FROM sys_users_admin_states suas
                LEFT JOIN cat_states cs ON cs.id = suas.state_id
                WHERE suas.admin_user_id = :admin_id
                ORDER BY state_code
            ");
            $stmt->execute([':admin_id' => $adminId]);
            $codes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $code = trim((string)($row['state_code'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            return $codes;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function ensureAdminProfile(
        PDO $target,
        string $authUserId,
        string $email,
        ?string $fullName,
        string $appRole,
        ?string $staffRole,
        bool $isSystemAdmin,
        bool $isActive,
        array $stateCodes,
        ?string $createdAt,
        ?string $updatedAt
    ): string {
        $existing = $target->prepare('SELECT id::text FROM public.user_profiles WHERE user_id = :user_id::uuid LIMIT 1');
        $existing->execute([':user_id' => $authUserId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow) {
            return (string)$existingRow['id'];
        }

        $state = $stateCodes !== [] ? $stateCodes[0] : null;
        $stmt = $target->prepare(
            "INSERT INTO public.user_profiles (
                user_id, email, full_name, app_role, staff_role, is_system_admin,
                onboarding_status, is_active, state, created_at, updated_at
            ) VALUES (
                :user_id::uuid, :email, :full_name, :app_role, :staff_role, :is_system_admin::boolean,
                'completed', :is_active::boolean, :state,
                COALESCE(:created_at::timestamptz, now()),
                COALESCE(:updated_at::timestamptz, now())
            )
            RETURNING id::text"
        );
        $stmt->execute([
            ':user_id' => $authUserId,
            ':email' => $email,
            ':full_name' => $fullName,
            ':app_role' => $appRole,
            ':staff_role' => $staffRole,
            ':is_system_admin' => $this->asPgBoolean($isSystemAdmin),
            ':is_active' => $this->asPgBoolean($isActive),
            ':state' => $state,
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
        return (string)$stmt->fetchColumn();
    }

    private function patchAuthUser(PDO $target, string $authUserId, bool $isActive, ?string $updatedAt): void
    {
        $bannedUntil = $isActive ? null : gmdate('Y-m-d H:i:sP');
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
    }

    private function requireAuthUserId(PDO $target, string $email): string
    {
        $authRow = $target->prepare('SELECT id::text FROM auth.users WHERE lower(trim(email)) = :email LIMIT 1');
        $authRow->execute([':email' => $email]);
        $auth = $authRow->fetch(PDO::FETCH_ASSOC);
        if (!$auth) {
            throw new \RuntimeException('Auth user not found after Admin API create for email: ' . $email);
        }
        return (string)$auth['id'];
    }

    private function mapAdminUserType(string $userType): string
    {
        $t = strtoupper(trim($userType));
        if ($t === 'DOCTOR') {
            return 'medical_director';
        }
        if (in_array($t, ['MASTER', 'PANEL', 'MINT'], true)) {
            return 'staff';
        }
        return 'staff';
    }

    private function isSystemAdminType(string $userType): bool
    {
        return strtoupper(trim($userType)) === 'MASTER';
    }

    private function mapStaffRole(string $userType): ?string
    {
        $t = strtoupper(trim($userType));
        if ($t === 'MASTER') {
            return 'super_admin';
        }
        if ($t === 'PANEL') {
            return 'custom';
        }
        return null;
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

    /**
     * @return 'created'|'exists'
     */
    private function createAuthUserViaApi(string $supabaseApiUrl, string $serviceRoleKey, string $email, string $password, array $legacyMeta): string
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
            return 'created';
        }

        $decoded = json_decode((string)$body, true);
        $msg = is_array($decoded) ? strtolower((string)($decoded['msg'] ?? $decoded['message'] ?? '')) : '';
        if (strpos($msg, 'already') !== false || strpos($msg, 'exists') !== false || strpos($msg, 'registered') !== false) {
            return 'exists';
        }

        throw new \RuntimeException('Supabase Admin API create user failed [' . $httpCode . ']: ' . (string)$body);
    }

    private function hasRoleConflict(string $intendedRole, ?string $existingRole): bool
    {
        $existing = strtolower(trim((string)$existingRole));
        if ($existing === '') {
            return false;
        }

        return $existing !== strtolower(trim($intendedRole));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildSkippedUserEntry(
        array $row,
        string $email,
        string $reason,
        array $extra = []
    ): array {
        return array_merge([
            'ts' => gmdate('c'),
            'event' => 'skipped',
            'skip_reason' => $reason,
            'legacy_admin_id' => (int)$row['id'],
            'legacy_uid' => $this->asNullableText($row['uid'] ?? null),
            'email' => $email,
            'legacy_username' => (string)($row['username'] ?? ''),
            'legacy_user_type' => (string)($row['user_type'] ?? ''),
            'intended_admin_app_role' => $this->mapAdminUserType((string)($row['user_type'] ?? '')),
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $skipped
     */
    private function writeSkippedCsv(string $csvPath, array $skipped): void
    {
        if (trim($csvPath) === '' || $skipped === []) {
            return;
        }
        $dir = dirname($csvPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($csvPath, 'wb');
        if ($fh === false) {
            return;
        }
        fputcsv($fh, [
            'legacy_admin_id',
            'legacy_uid',
            'email',
            'legacy_user_type',
            'skip_reason',
            'intended_admin_app_role',
            'existing_mapped_app_role',
            'duplicate_sources',
            'auth_user_id',
            'legacy_sys_user_id',
        ]);
        foreach ($skipped as $s) {
            fputcsv($fh, [
                $s['legacy_admin_id'] ?? '',
                $s['legacy_uid'] ?? '',
                $s['email'] ?? '',
                $s['legacy_user_type'] ?? '',
                $s['skip_reason'] ?? '',
                $s['intended_admin_app_role'] ?? '',
                $s['existing_mapped_app_role'] ?? '',
                is_array($s['duplicate_sources'] ?? null) ? implode('|', $s['duplicate_sources']) : '',
                $s['auth_user_id'] ?? '',
                $s['legacy_sys_user_id'] ?? '',
            ]);
        }
        fclose($fh);
    }

    /**
     * @param list<array<string, mixed>> $duplicates
     */
    private function writeDuplicatesCsv(string $csvPath, array $duplicates): void
    {
        if (trim($csvPath) === '' || $duplicates === []) {
            return;
        }
        $dir = dirname($csvPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($csvPath, 'wb');
        if ($fh === false) {
            return;
        }
        fputcsv($fh, [
            'legacy_admin_id',
            'legacy_uid',
            'email',
            'legacy_user_type',
            'duplicate_sources',
            'legacy_sys_user_id',
            'auth_user_id',
            'user_profile_id',
            'existing_mapped_app_role',
            'intended_admin_app_role',
        ]);
        foreach ($duplicates as $d) {
            fputcsv($fh, [
                $d['legacy_admin_id'] ?? '',
                $d['legacy_uid'] ?? '',
                $d['email'] ?? '',
                $d['legacy_user_type'] ?? '',
                is_array($d['duplicate_sources'] ?? null) ? implode('|', $d['duplicate_sources']) : '',
                $d['legacy_sys_user_id'] ?? '',
                $d['auth_user_id'] ?? '',
                $d['user_profile_id'] ?? '',
                $d['existing_mapped_app_role'] ?? '',
                $d['intended_admin_app_role'] ?? '',
            ]);
        }
        fclose($fh);
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

    private function asNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    private function asPgBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
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

    private function normalizeEmailForAuth(string $raw): string
    {
        $email = strtolower(trim(str_replace(["\0", "\r", "\n", "\x0c"], '', $raw)));
        return preg_replace('/\x{00A0}/u', '', $email) ?? $email;
    }

    private function isRFC5322LooseEmail(string $email): bool
    {
        if (strlen($email) > 254) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function appendLogLine(string $logFile, array $payload): void
    {
        $path = trim($logFile);
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) {
            return;
        }
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    }
}
