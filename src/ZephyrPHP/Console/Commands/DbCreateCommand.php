<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:create',
    description: 'Create the database if it does not exist'
)]
class DbCreateCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->loadEnv();

        $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        $database = $_ENV['DB_DATABASE'] ?? '';

        if (empty($database)) {
            $this->error('Database name not configured. Run db:setup first.');
            return self::FAILURE;
        }

        if ($driver === 'sqlite') {
            $path = $this->basePath($database);
            $dir = dirname($path);
            $this->ensureDirectory($dir);

            if (!file_exists($path)) {
                touch($path);
                $this->success("SQLite database created: {$database}");
            } else {
                $this->info("SQLite database already exists: {$database}");
            }
            return self::SUCCESS;
        }

        // MySQL/PostgreSQL/SQL Server
        try {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $username = $_ENV['DB_USERNAME'] ?? 'root';
            $password = $_ENV['DB_PASSWORD'] ?? '';

            $dsn = "{$driver}:host={$host};port={$port}";
            $pdo = new \PDO($dsn, $username, $password);

            $charset = $driver === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';
            $sql = "CREATE DATABASE IF NOT EXISTS `{$database}` {$charset}";

            if ($driver === 'pgsql') {
                // PostgreSQL syntax
                $result = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '{$database}'");
                if ($result->fetchColumn()) {
                    $this->info("Database already exists: {$database}");
                    return self::SUCCESS;
                }
                $sql = "CREATE DATABASE \"{$database}\"";
            }

            $pdo->exec($sql);
            $this->success("Database created: {$database}");
            return self::SUCCESS;
        } catch (\PDOException $e) {
            $this->error('Failed to create database: ' . $e->getMessage());
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
