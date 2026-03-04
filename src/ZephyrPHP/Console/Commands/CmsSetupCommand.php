<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cms:setup',
    description: 'Set up the CMS module database tables and upload directory'
)]
class CmsSetupCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('CMS Module Setup');

        // Verify prerequisites
        if (!$this->isModuleEnabled('database')) {
            $this->error('Database module is required but not enabled.');
            $this->line('  Run: php craftsman add database');
            return self::FAILURE;
        }

        if (!$this->isModuleEnabled('auth')) {
            $this->error('Auth module is required but not enabled.');
            $this->line('  Run: php craftsman add auth');
            return self::FAILURE;
        }

        // Check CMS module is installed
        if (!class_exists('ZephyrPHP\\Cms\\CmsServiceProvider')) {
            $this->error('CMS module is not installed.');
            $this->line('  Run: php craftsman add cms');
            return self::FAILURE;
        }

        $this->loadEnv();

        // Find CMS models directory
        $cmsModelsDir = $this->findCmsModelsDir();
        if (!$cmsModelsDir) {
            $this->error('Could not find CMS models directory.');
            return self::FAILURE;
        }

        $this->line("  CMS models: <info>{$cmsModelsDir}</info>");
        $this->line('');

        try {
            // Also include app models so Doctrine can resolve relationships
            $appModelsDir = $this->basePath('app/Models');
            $paths = [$cmsModelsDir];
            if (is_dir($appModelsDir)) {
                $paths[] = $appModelsDir;
            }

            $em = $this->createEntityManager($paths);
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);

            // Get only CMS entity metadata
            $allMetadata = $em->getMetadataFactory()->getAllMetadata();
            $cmsMetadata = array_filter($allMetadata, function ($meta) {
                return str_starts_with($meta->getName(), 'ZephyrPHP\\Cms\\');
            });

            if (empty($cmsMetadata)) {
                $this->error('No CMS entity metadata found.');
                return self::FAILURE;
            }

            $this->section('Creating CMS Tables');

            $sqls = $schemaTool->getUpdateSchemaSql(array_values($cmsMetadata));

            if (empty($sqls)) {
                $this->info('CMS tables already exist and are up to date.');
            } else {
                foreach ($sqls as $sql) {
                    $this->line("  " . $sql);
                }
                $this->line('');

                $schemaTool->updateSchema(array_values($cmsMetadata));
                $this->success('CMS tables created (' . count($sqls) . ' statements executed)');
            }

            // Create upload directory
            $this->section('Upload Directory');
            $uploadDir = $this->basePath('storage/cms/uploads');
            $this->ensureDirectory($uploadDir);
            $this->line("  Created: <info>{$uploadDir}</info>");

            // Create .gitignore in uploads
            $gitignore = $uploadDir . '/.gitignore';
            if (!file_exists($gitignore)) {
                file_put_contents($gitignore, "*\n!.gitignore\n");
            }

            $this->line('');
            $this->success('CMS module setup complete!');
            $this->line('');
            $this->note('Next steps:');
            $this->line('  1. Run <info>php craftsman serve</info> to start the development server');
            $this->line('  2. Visit <info>/cms</info> to access the CMS builder');
            $this->line('');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('CMS setup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function isModuleEnabled(string $name): bool
    {
        $configPath = $this->basePath('config/modules.php');
        if (!file_exists($configPath)) {
            return false;
        }

        $modules = require $configPath;

        if (isset($modules[$name])) {
            return is_array($modules[$name]) ? ($modules[$name]['enabled'] ?? true) : (bool) $modules[$name];
        }

        return false;
    }

    private function findCmsModelsDir(): ?string
    {
        // Check vendor directory
        $vendorPath = $this->basePath('vendor/zephyrphp/cms/src/Models');
        if (is_dir($vendorPath)) {
            return $vendorPath;
        }

        // Check if CMS is symlinked or in development
        $possiblePaths = [
            $this->basePath('../cms/src/Models'),
            $this->basePath('cms/src/Models'),
        ];

        foreach ($possiblePaths as $path) {
            $realPath = realpath($path);
            if ($realPath && is_dir($realPath)) {
                return $realPath;
            }
        }

        // Try to find via reflection
        if (class_exists('ZephyrPHP\\Cms\\Models\\Collection')) {
            $ref = new \ReflectionClass('ZephyrPHP\\Cms\\Models\\Collection');
            return dirname($ref->getFileName());
        }

        return null;
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

    private function createEntityManager(array $paths): \Doctrine\ORM\EntityManager
    {
        $driver = $_ENV['DB_CONNECTION'] ?? $_ENV['DB_DRIVER'] ?? 'pdo_mysql';
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
            $paths,
            true
        );

        return \Doctrine\ORM\EntityManager::create($conn, $config);
    }
}
