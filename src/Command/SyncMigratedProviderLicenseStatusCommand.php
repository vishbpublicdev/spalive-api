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
 * Sync public.provider_licenses.verification_status from legacy sys_licences.status.
 *
 * Disable trigger first: sql/provider_licenses_verification_status_sync_trigger.sql
 *
 *   bin/cake sync_migrated_provider_license_status --config=config/migration_sys_users_LIVE.php
 */
class SyncMigratedProviderLicenseStatusCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(
                'Set provider_licenses.verification_status from legacy sys_licences (approved/rejected). '
                . 'Disable provider_licenses_enforce_verification_status trigger first.'
            )
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Path to migration config (legacy MySQL + target Postgres).',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'default' => false,
                'help' => 'Report changes only; no UPDATE.',
            ])
            ->addOption('batch', [
                'short' => 'b',
                'default' => 500,
                'help' => 'Legacy ids per MySQL fetch batch (default 500).',
            ])
            ->addOption('max-rows', [
                'default' => 0,
                'help' => 'Stop after N provider_licenses rows processed (0 = all).',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $dryRun = (bool)$args->getOption('dry-run');
        $batch = max(1, (int)$args->getOption('batch'));
        $maxRows = max(0, (int)$args->getOption('max-rows'));

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['legacy'], $cfg['target'])) {
            $io->err("Invalid config: {$configPath}");

            return static::CODE_ERROR;
        }

        try {
            $legacy = $this->pdoMysql($cfg['legacy']);
            $target = $this->pdoPg($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connect failed: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        $io->out('Building legacy_licence_id -> provider_license_id links (map + admin_notes)...');
        $plToLegacyIds = $this->buildProviderLicenseLegacyLinks($target);

        if ($plToLegacyIds === []) {
            $io->warning('No migrated provider licenses found (map table or admin_notes).');

            return static::CODE_SUCCESS;
        }

        ksort($plToLegacyIds);

        $allLegacyIds = [];
        foreach ($plToLegacyIds as $legacyIds) {
            foreach ($legacyIds as $lid) {
                $allLegacyIds[$lid] = true;
            }
        }
        $legacyIdList = array_keys($allLegacyIds);
        sort($legacyIdList, SORT_NUMERIC);

        $io->out(sprintf(
            'Found %d provider_licenses rows linked to %d legacy sys_licences ids.%s',
            count($plToLegacyIds),
            count($legacyIdList),
            $maxRows > 0 ? " max-rows={$maxRows}" : ''
        ));

        $legacyStatusById = $this->fetchLegacyStatuses($legacy, $legacyIdList, $batch);

        $updated = 0;
        $skipped = 0;
        $unchanged = 0;
        $processed = 0;

        $updateSt = $target->prepare(
            'UPDATE public.provider_licenses
             SET verification_status = :status, updated_at = now()
             WHERE id = :id::uuid
               AND verification_status IS DISTINCT FROM :status'
        );

        foreach ($plToLegacyIds as $providerLicenseId => $legacyIds) {
            if ($maxRows > 0 && $processed >= $maxRows) {
                break;
            }
            $processed++;

            $targetStatus = $this->resolveTargetStatus($legacyIds, $legacyStatusById);
            if ($targetStatus === null) {
                $skipped++;
                continue;
            }

            $current = $this->currentVerificationStatus($target, $providerLicenseId);
            if ($current === $targetStatus) {
                $unchanged++;
                continue;
            }

            if ($dryRun) {
                $io->out(sprintf(
                    'dry-run UPDATE pl=%s legacy_ids=[%s] %s -> %s',
                    $providerLicenseId,
                    implode(',', array_map('strval', $legacyIds)),
                    $current ?? 'null',
                    $targetStatus
                ));
                $updated++;
                continue;
            }

            $updateSt->execute([
                ':id' => $providerLicenseId,
                ':status' => $targetStatus,
            ]);
            if ($updateSt->rowCount() > 0) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        $io->success(sprintf(
            'Sync done. processed=%d updated=%d unchanged=%d skipped_no_approved_rejected=%d dry-run=%s',
            $processed,
            $updated,
            $unchanged,
            $skipped,
            $dryRun ? 'yes' : 'no'
        ));

        if (!$dryRun) {
            $io->out('Re-enable trigger: sql/provider_licenses_verification_status_sync_trigger.sql');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @return array<string, list<int>> provider_license_id => legacy ids
     */
    private function buildProviderLicenseLegacyLinks(PDO $target): array
    {
        $plToLegacyIds = [];

        $mapSt = $target->query(
            'SELECT DISTINCT legacy_licence_id, provider_license_id::text
             FROM public.migration_provider_licenses_map
             ORDER BY legacy_licence_id'
        );
        while ($row = $mapSt->fetch(PDO::FETCH_ASSOC)) {
            $plId = (string)$row['provider_license_id'];
            $lid = (int)$row['legacy_licence_id'];
            $plToLegacyIds[$plId] ??= [];
            if (!in_array($lid, $plToLegacyIds[$plId], true)) {
                $plToLegacyIds[$plId][] = $lid;
            }
        }

        $notesSt = $target->query(
            "SELECT id::text, admin_notes
             FROM public.provider_licenses
             WHERE admin_notes LIKE 'Legacy import licence #%'"
        );
        while ($row = $notesSt->fetch(PDO::FETCH_ASSOC)) {
            $plId = (string)$row['id'];
            $lid = $this->legacyIdFromAdminNotes((string)($row['admin_notes'] ?? ''));
            if ($lid === null) {
                continue;
            }
            $plToLegacyIds[$plId] ??= [];
            if (!in_array($lid, $plToLegacyIds[$plId], true)) {
                $plToLegacyIds[$plId][] = $lid;
            }
        }

        return $plToLegacyIds;
    }

    /**
     * @param list<int> $legacyIds
     * @param array<int, string> $legacyStatusById
     */
    private function resolveTargetStatus(array $legacyIds, array $legacyStatusById): ?string
    {
        $hasApproved = false;
        $hasRejected = false;

        foreach ($legacyIds as $lid) {
            $mapped = $this->mapLegacyStatus($legacyStatusById[$lid] ?? '');
            if ($mapped === 'approved') {
                $hasApproved = true;
            } elseif ($mapped === 'rejected') {
                $hasRejected = true;
            }
        }

        if ($hasApproved) {
            return 'approved';
        }
        if ($hasRejected) {
            return 'rejected';
        }

        return null;
    }

    private function mapLegacyStatus(string $legacyStatus): ?string
    {
        switch (strtoupper(trim($legacyStatus))) {
            case 'APPROVED':
                return 'approved';
            case 'REJECTED':
            case 'REJECT':
                return 'rejected';
            default:
                return null;
        }
    }

    /**
     * @param list<int> $legacyIdList
     * @return array<int, string>
     */
    private function fetchLegacyStatuses(PDO $legacy, array $legacyIdList, int $batch): array
    {
        $out = [];
        foreach (array_chunk($legacyIdList, $batch) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $legacy->prepare(
                "SELECT id, status
                 FROM sys_licences
                 WHERE id IN ({$placeholders})
                   AND COALESCE(deleted, 0) = 0"
            );
            $st->execute($chunk);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[(int)$row['id']] = (string)($row['status'] ?? '');
            }
        }

        return $out;
    }

    private function legacyIdFromAdminNotes(string $notes): ?int
    {
        if (preg_match('/Legacy import licence #(\d+)/', $notes, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private function currentVerificationStatus(PDO $target, string $providerLicenseId): ?string
    {
        $st = $target->prepare(
            'SELECT verification_status FROM public.provider_licenses WHERE id = :id::uuid LIMIT 1'
        );
        $st->execute([':id' => $providerLicenseId]);
        $val = $st->fetchColumn();

        return $val !== false ? (string)$val : null;
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
}
