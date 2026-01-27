<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:schema',
    description: 'Create database tables from entities'
)]
class DbSchemaCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Drop and recreate all tables (WARNING: destroys data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('Database Schema Update');

        $modelsDir = $this->basePath('app/Models');

        if (!is_dir($modelsDir)) {
            $this->error('Models directory not found: app/Models');
            return self::FAILURE;
        }

        $entityFiles = glob($modelsDir . '/*.php');

        if (empty($entityFiles)) {
            $this->info('No models found in app/Models');
            return self::SUCCESS;
        }

        $this->line("Found " . count($entityFiles) . " model(s)");
        $this->line('');

        // Load env for database connection
        $this->loadEnv();

        // Check if Doctrine EntityManager is available
        if (!class_exists('Doctrine\\ORM\\EntityManager')) {
            $this->warning('Doctrine ORM is not installed.');
            $this->line('');
            $this->line('Install with: composer require doctrine/orm');
            return self::FAILURE;
        }

        try {
            // Create EntityManager
            $em = $this->createEntityManager($modelsDir);

            // Get schema tool
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();

            if (empty($metadata)) {
                $this->info('No entity metadata found.');
                return self::SUCCESS;
            }

            // Show what will be created/updated
            $this->section('Entities found:');
            foreach ($metadata as $meta) {
                $this->line("  - " . $meta->getName());
            }

            $this->line('');

            $force = $input->getOption('force');

            if ($force) {
                // Force mode: drop and recreate all tables
                $this->warning('FORCE MODE: This will DROP all tables and recreate them!');
                $this->warning('All data will be LOST!');
                $this->line('');

                if (!$this->confirm('Are you sure you want to continue?', false)) {
                    $this->line('Aborted.');
                    return self::SUCCESS;
                }

                // Get drop SQL
                $dropSqls = $schemaTool->getDropSchemaSQL($metadata);
                $createSqls = $schemaTool->getCreateSchemaSql($metadata);

                $this->section('SQL to execute:');
                foreach ($dropSqls as $sql) {
                    $this->line("  " . $sql);
                }
                foreach ($createSqls as $sql) {
                    $this->line("  " . $sql);
                }
                $this->line('');

                // Drop and recreate
                $schemaTool->dropSchema($metadata);
                $schemaTool->createSchema($metadata);

                $this->success('Database schema recreated successfully!');
                return self::SUCCESS;
            }

            // Normal mode: update schema
            // Get the SQL that would be executed
            $sqls = $schemaTool->getUpdateSchemaSql($metadata);

            if (empty($sqls)) {
                $this->info('Schema is already up to date.');
                $this->line('');
                $this->line('Tip: Use --force to drop and recreate tables with all constraints.');
                return self::SUCCESS;
            }

            $this->section('SQL to execute:');
            foreach ($sqls as $sql) {
                $this->line("  " . $sql);
            }
            $this->line('');

            if (!$this->confirm('Execute these SQL statements?', true)) {
                $this->line('Aborted.');
                return self::SUCCESS;
            }

            // Update schema
            $schemaTool->updateSchema($metadata);

            $this->success('Database schema updated successfully! (' . count($sqls) . ' statements executed)');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Schema update failed: ' . $e->getMessage());
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

    private function createEntityManager(string $modelsDir): \Doctrine\ORM\EntityManager
    {
        // Support both DB_DRIVER and DB_CONNECTION env vars
        $driver = $_ENV['DB_CONNECTION'] ?? $_ENV['DB_DRIVER'] ?? 'pdo_mysql';

        // Normalize driver name (remove pdo_ prefix if present, we'll add it back)
        $driver = str_replace('pdo_', '', $driver);

        if ($driver === 'sqlite') {
            $dbPath = $this->basePath($_ENV['DB_DATABASE'] ?? 'database/database.sqlite');
            $conn = [
                'driver' => 'pdo_sqlite',
                'path' => $dbPath,
            ];
        } else {
            $conn = [
                'driver' => 'pdo_' . $driver,
                'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['DB_PORT'] ?? '3306',
                'dbname' => $_ENV['DB_DATABASE'] ?? 'zephyr',
                'user' => $_ENV['DB_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'charset' => 'utf8mb4',
            ];
        }

        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(
            [$modelsDir],
            true // Dev mode
        );

        return \Doctrine\ORM\EntityManager::create($conn, $config);
    }
}
