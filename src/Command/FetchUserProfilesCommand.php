<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use PDO;
use Throwable;

class FetchUserProfilesCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Fetch rows from Supabase public.user_profiles.')
            ->addOption('config', [
                'default' => CONFIG . 'migration_sys_users.php',
                'help' => 'Absolute path to migration config file.',
            ])
            ->addOption('limit', [
                'short' => 'l',
                'default' => 50,
                'help' => 'Number of rows to fetch (default 50).',
            ])
            ->addOption('offset', [
                'default' => 0,
                'help' => 'Row offset (default 0).',
            ])
            ->addOption('app-role', [
                'default' => '',
                'help' => 'Optional filter by app_role.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $configPath = (string)$args->getOption('config');
        $limit = max(1, (int)$args->getOption('limit'));
        $offset = max(0, (int)$args->getOption('offset'));
        $appRole = trim((string)$args->getOption('app-role'));

        if (!is_file($configPath)) {
            $io->err("Config file not found: {$configPath}");
            return static::CODE_ERROR;
        }

        $cfg = require $configPath;
        if (!is_array($cfg) || !isset($cfg['target'])) {
            $io->err("Invalid config format in: {$configPath}");
            return static::CODE_ERROR;
        }

        try {
            $pdo = $this->makePgPdo($cfg['target']);
        } catch (Throwable $e) {
            $io->err('Supabase connection failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }

        try {
            if ($appRole !== '') {
                $sql = "
                    SELECT id, user_id, email, full_name, app_role, is_active, created_at, updated_at
                    FROM public.user_profiles
                    WHERE app_role = :app_role
                    ORDER BY created_at DESC NULLS LAST
                    LIMIT :lim OFFSET :off
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':app_role', $appRole);
            } else {
                $sql = "
                    SELECT id, user_id, email, full_name, app_role, is_active, created_at, updated_at
                    FROM public.user_profiles
                    ORDER BY created_at DESC NULLS LAST
                    LIMIT :lim OFFSET :off
                ";
                $stmt = $pdo->prepare($sql);
            }

            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $io->out(sprintf('Fetched %d rows from public.user_profiles', count($rows)));
            foreach ($rows as $row) {
                $io->out(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return static::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->err('Query failed: ' . $e->getMessage());
            return static::CODE_ERROR;
        }
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

