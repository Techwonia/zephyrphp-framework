<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:setup',
    description: 'Configure database connection interactively'
)]
class DbSetupCommand extends BaseCommand
{
    // Map user-friendly names to Doctrine DBAL driver names
    private const DRIVER_MAP = [
        'mysql'   => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',  // MariaDB uses MySQL driver
        'pgsql'   => 'pdo_pgsql',
        'sqlite'  => 'pdo_sqlite',
        'sqlsrv'  => 'pdo_sqlsrv',
    ];

    // Map Doctrine drivers to PDO DSN prefix
    private const PDO_DSN_MAP = [
        'pdo_mysql'  => 'mysql',
        'pdo_pgsql'  => 'pgsql',
        'pdo_sqlite' => 'sqlite',
        'pdo_sqlsrv' => 'sqlsrv',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('Database Setup');

        // Database driver selection
        $driverOptions = [
            'mysql'   => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql'   => 'PostgreSQL',
            'sqlite'  => 'SQLite',
            'sqlsrv'  => 'SQL Server',
        ];

        $driverChoice = $this->choice(
            'Select database driver',
            array_values($driverOptions),
            'MySQL'
        );

        // Get driver key from display name
        $selectedDriver = array_search($driverChoice, $driverOptions);

        // Get Doctrine DBAL driver name
        $doctrineDriver = self::DRIVER_MAP[$selectedDriver];

        $config = [
            'DB_CONNECTION' => $doctrineDriver, // Doctrine DBAL driver name (pdo_mysql, pdo_pgsql, etc.)
        ];

        if ($selectedDriver === 'sqlite') {
            $dbPath = $this->ask('Database path', 'database/database.sqlite');
            $config['DB_DATABASE'] = $dbPath;

            // Create SQLite file if it doesn't exist
            $fullPath = $this->basePath($dbPath);
            $dir = dirname($fullPath);
            $this->ensureDirectory($dir);
            if (!file_exists($fullPath)) {
                touch($fullPath);
                $this->info("Created SQLite database: {$dbPath}");
            }
        } else {
            $config['DB_HOST'] = $this->ask('Database host', '127.0.0.1');
            $config['DB_PORT'] = $this->ask('Database port', $this->getDefaultPort($selectedDriver));
            $config['DB_DATABASE'] = $this->ask('Database name', 'zephyr');
            $config['DB_USERNAME'] = $this->ask('Database username', 'root');
            $config['DB_PASSWORD'] = $this->askHidden('Database password') ?? '';
        }

        // Update .env file
        $this->updateEnvFile($config);

        $this->success('Database configuration saved to .env');

        // Test connection and optionally create database
        if ($this->confirm('Test the connection now?', true)) {
            $result = $this->testConnection($config);

            // If connection to server works but database doesn't exist, offer to create it
            if ($result === self::FAILURE && $selectedDriver !== 'sqlite') {
                if ($this->confirm("Would you like to create the database '{$config['DB_DATABASE']}'?", true)) {
                    return $this->createDatabase($config);
                }
            }

            return $result;
        }

        return self::SUCCESS;
    }

    private function getDefaultPort(string $driver): string
    {
        return match($driver) {
            'mysql', 'mariadb' => '3306',
            'pgsql' => '5432',
            'sqlsrv' => '1433',
            default => '3306',
        };
    }

    private function updateEnvFile(array $config): void
    {
        $envPath = $this->basePath('.env');

        if (!file_exists($envPath)) {
            $envExample = $this->basePath('.env.example');
            if (file_exists($envExample)) {
                copy($envExample, $envPath);
            } else {
                file_put_contents($envPath, '');
            }
        }

        $content = file_get_contents($envPath);

        foreach ($config as $key => $value) {
            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $content .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $content);
    }

    /**
     * Get PDO DSN prefix from Doctrine driver name
     */
    private function getPdoDsnPrefix(string $doctrineDriver): string
    {
        return self::PDO_DSN_MAP[$doctrineDriver] ?? 'mysql';
    }

    private function testConnection(array $config): int
    {
        $this->line('');
        $this->line('Testing connection...');

        try {
            $doctrineDriver = $config['DB_CONNECTION'];
            $pdoDriver = $this->getPdoDsnPrefix($doctrineDriver);

            if ($doctrineDriver === 'pdo_sqlite') {
                $dsn = "sqlite:{$this->basePath($config['DB_DATABASE'])}";
                new \PDO($dsn);
                $this->success('Connection successful!');
                return self::SUCCESS;
            }

            // First try connecting to the specific database
            try {
                $dsn = "{$pdoDriver}:host={$config['DB_HOST']};port={$config['DB_PORT']};dbname={$config['DB_DATABASE']}";
                new \PDO($dsn, $config['DB_USERNAME'], $config['DB_PASSWORD']);
                $this->success('Connection successful!');
                return self::SUCCESS;
            } catch (\PDOException $e) {
                // Check if error is "database not found"
                if (str_contains($e->getMessage(), '1049') || str_contains($e->getMessage(), 'Unknown database')) {
                    $this->warning("Database '{$config['DB_DATABASE']}' does not exist.");
                    return self::FAILURE;
                }
                throw $e;
            }
        } catch (\PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function createDatabase(array $config): int
    {
        $this->line('');
        $this->line('Creating database...');

        try {
            $doctrineDriver = $config['DB_CONNECTION'];
            $pdoDriver = $this->getPdoDsnPrefix($doctrineDriver);
            $dbName = $config['DB_DATABASE'];

            // Validate database name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
                $this->error('Invalid database name. Only letters, numbers, and underscores are allowed.');
                return self::FAILURE;
            }

            // Connect without database name
            $dsn = "{$pdoDriver}:host={$config['DB_HOST']};port={$config['DB_PORT']}";
            $pdo = new \PDO($dsn, $config['DB_USERNAME'], $config['DB_PASSWORD']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Create database with proper charset
            if ($pdoDriver === 'mysql') {
                $charset = 'utf8mb4';
                $collation = 'utf8mb4_unicode_ci';
                $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}";
            } elseif ($pdoDriver === 'pgsql') {
                $sql = "CREATE DATABASE \"{$dbName}\" ENCODING 'UTF8'";
            } else {
                $sql = "CREATE DATABASE [{$dbName}]";
            }

            $pdo->exec($sql);

            $this->success("Database '{$dbName}' created successfully!");
            $this->line('');
            $this->info('You can now run: php craftsman db:schema');

            return self::SUCCESS;
        } catch (\PDOException $e) {
            // PostgreSQL throws error if database exists
            if (str_contains($e->getMessage(), 'already exists')) {
                $this->success("Database '{$config['DB_DATABASE']}' already exists!");
                return self::SUCCESS;
            }

            $this->error('Failed to create database: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
