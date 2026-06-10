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
 * Roll back model patients migration via migration_model_patients_map.
 */
class RollbackModelPatientsMigrationCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Rollback model patients migration (map + enrollments + model_patients rows).')
            ->addOption('config', ['default' => CONFIG . 'migration_sys_users.php'])
            ->addOption('rewind-to', [
                'default' => -1,
                'help' => 'Delete map rows with legacy_data_model_patient_id > this value (-1 = delete all).',
            ])
            ->addOption('dry-run', ['boolean' => true, 'default' => false])
            ->addOption('reset-checkpoint', [
                'boolean' => true,
                'default' => false,
                'help' => 'Clear migration_checkpoints row data_model_patient:model_patients.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $configPath = (string)$args->getOption('config');
        $rewindTo = (int)$args->getOption('rewind-to');
        $dryRun = (bool)$args->getOption('dry-run');
        $resetCheckpoint = (bool)$args->getOption('reset-checkpoint');

        if (!is_file($configPath)) {
            $io->err("Config not found: {$configPath}");

            return static::CODE_ERROR;
        }

        $cfg = require $configPath;

        try {
            $target = $this->pdoPg($cfg['target']);
        } catch (Throwable $e) {
            $io->err('DB connect failed: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        if ($rewindTo < 0) {
            $rewindTo = 0;
            $io->out('rewind-to=0: removing all rows from migration_model_patients_map');
        }

        $st = $target->prepare(
            'SELECT legacy_data_model_patient_id, model_patient_id::text, model_patient_session_enrollment_id::text
             FROM public.migration_model_patients_map
             WHERE legacy_data_model_patient_id > :rewind
             ORDER BY legacy_data_model_patient_id DESC'
        );
        $st->execute([':rewind' => $rewindTo]);
        $rows = $st->fetchAll();

        if ($rows === []) {
            $io->out('No map rows to roll back.');

            return static::CODE_SUCCESS;
        }

        $io->out(sprintf('Rolling back %d map row(s) dry-run=%s', count($rows), $dryRun ? 'yes' : 'no'));

        $deleted = 0;
        foreach ($rows as $row) {
            $legacyId = (int)$row['legacy_data_model_patient_id'];
            $enrollmentId = $row['model_patient_session_enrollment_id'] ?? null;
            $modelPatientId = $row['model_patient_id'] ?? null;

            if ($dryRun) {
                $io->out("  [dry-run] legacy_id={$legacyId} model_patient={$modelPatientId}");
                $deleted++;
                continue;
            }

            try {
                $target->beginTransaction();

                if (is_string($enrollmentId) && $enrollmentId !== '') {
                    $target->prepare(
                        'DELETE FROM public.model_patient_session_enrollments WHERE id = :id::uuid'
                    )->execute([':id' => $enrollmentId]);
                }

                if (is_string($modelPatientId) && $modelPatientId !== '') {
                    $target->prepare(
                        'DELETE FROM public.model_patients WHERE id = :id::uuid'
                    )->execute([':id' => $modelPatientId]);
                }

                $target->prepare(
                    'DELETE FROM public.migration_model_patients_map WHERE legacy_data_model_patient_id = :id'
                )->execute([':id' => $legacyId]);

                $target->commit();
                $deleted++;
            } catch (Throwable $e) {
                if ($target->inTransaction()) {
                    $target->rollBack();
                }
                $io->err("Failed legacy_id={$legacyId}: " . $e->getMessage());

                return static::CODE_ERROR;
            }
        }

        if ($resetCheckpoint && !$dryRun) {
            $target->prepare(
                'DELETE FROM public.migration_checkpoints WHERE name = :n'
            )->execute([':n' => 'data_model_patient:model_patients']);
            $io->out('Checkpoint cleared.');
        }

        $io->success("Rollback complete. deleted={$deleted}");

        return static::CODE_SUCCESS;
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
