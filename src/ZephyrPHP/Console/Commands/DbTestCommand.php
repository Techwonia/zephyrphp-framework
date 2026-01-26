<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:test',
    description: 'Test the database connection'
)]
class DbTestCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->loadEnv();

        $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        $database = $_ENV['DB_DATABASE'] ?? '';

        if (empty($database)) {
            $this->error('Database not configured. Run db:setup first.');
            return self::FAILURE;
        }

        $this->line('Testing database connection...');
        $this->line('');

        try {
            if ($driver === 'sqlite') {
                $path = $this->basePath($database);
                if (!file_exists($path)) {
                    $this->error("SQLite database file not found: {$database}");
                    return self::FAILURE;
                }
                $dsn = "sqlite:{$path}";
                $pdo = new \PDO($dsn);
            } else {
                $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
                $port = $_ENV['DB_PORT'] ?? '3306';
                $username = $_ENV['DB_USERNAME'] ?? 'root';
                $password = $_ENV['DB_PASSWORD'] ?? '';

                $dsn = "{$driver}:host={$host};port={$port};dbname={$database}";
                $pdo = new \PDO($dsn, $username, $password);
            }

            // Show connection info
            $rows = [
                ['Driver', $driver],
                ['Database', $database],
            ];

            if ($driver !== 'sqlite') {
                $rows[] = ['Host', $_ENV['DB_HOST'] ?? '127.0.0.1'];
                $rows[] = ['Port', $_ENV['DB_PORT'] ?? '3306'];
                $rows[] = ['Username', $_ENV['DB_USERNAME'] ?? 'root'];
            }

            $rows[] = ['Status', 'Connected'];

            $this->table(['Property', 'Value'], $rows);
            $this->line('');
            $this->success('Database connection successful!');

            return self::SUCCESS;
        } catch (\PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function loadEnv(): void
    {
        $envPath = $this->basePath('.env');
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                }
            }
        }
    }
}
