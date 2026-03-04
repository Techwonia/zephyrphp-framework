<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'add',
    description: 'Add a module package to the project'
)]
class AddModuleCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $moduleName = $input->getArgument('module');

        $this->line("Adding module: {$moduleName}");
        $this->line('');

        // Determine package name (use zephyrphp vendor prefix)
        $packageName = str_contains($moduleName, '/') ? $moduleName : "zephyrphp/{$moduleName}";

        // Run composer require
        $this->line("Running: composer require {$packageName}");
        $this->line('');

        $result = 0;
        passthru("composer require {$packageName}", $result);

        if ($result !== 0) {
            $this->error("Failed to install module: {$moduleName}");
            return self::FAILURE;
        }

        // Enable the module in config/modules.php
        $shortName = str_contains($moduleName, '/') ? explode('/', $moduleName)[1] : $moduleName;
        $configPath = $this->basePath('config/modules.php');

        if (file_exists($configPath)) {
            // Read and update existing config file
            $content = file_get_contents($configPath);

            // Check if module entry exists
            if (preg_match("/['\"]" . preg_quote($shortName, '/') . "['\"]\s*=>/", $content)) {
                // Module exists - update it to true
                $content = preg_replace(
                    "/(['\"]" . preg_quote($shortName, '/') . "['\"])\s*=>\s*false/",
                    "$1 => true",
                    $content
                );
            } else {
                // Module doesn't exist in config - add it before the closing ];
                $content = preg_replace(
                    "/(\];?\s*)$/",
                    "\n    // Added by: php craftsman add {$shortName}\n    '{$shortName}' => true,\n$1",
                    $content
                );
            }

            file_put_contents($configPath, $content);
        } else {
            // Create new config file
            $configDir = dirname($configPath);
            $this->ensureDirectory($configDir);

            $content = <<<PHP
<?php

return [
    '{$shortName}' => true,
];
PHP;
            file_put_contents($configPath, $content);
        }

        $this->line('');
        $this->success("Module '{$moduleName}' has been installed and enabled.");

        // Run post-install hooks for specific modules
        $this->runPostInstallHooks($shortName);

        return self::SUCCESS;
    }

    /**
     * Run post-install hooks for specific modules
     */
    private function runPostInstallHooks(string $moduleName): void
    {
        switch ($moduleName) {
            case 'database':
                $this->line('');
                if ($this->confirm('Would you like to configure the database connection now?', true)) {
                    $this->getApplication()->find('db:setup')->run(
                        new \Symfony\Component\Console\Input\ArrayInput([]),
                        $this->output
                    );
                } else {
                    $this->note('You can configure the database later with: php craftsman db:setup');
                }

                // Offer unified auth system setup
                $this->line('');
                $this->section('Authentication System');
                $this->line('  Set up a complete auth system with:');
                $this->line('    - User model with roles');
                $this->line('    - Login & Register pages');
                $this->line('    - Dashboard (/v1/dashboard)');
                $this->line('    - Settings page (profile, password, roles)');
                $this->line('');

                $setupChoice = $this->choice(
                    'How would you like to proceed?',
                    [
                        'Auto setup (Recommended) — Install auth + authorization and scaffold everything',
                        'Manual — I\'ll add modules myself later',
                    ]
                );

                if (str_starts_with($setupChoice, 'Auto')) {
                    $this->runAutoAuthSetup();
                } else {
                    $this->note('You can add modules later:');
                    $this->line('  php craftsman add auth');
                    $this->line('  php craftsman add authorization');
                    $this->line('  php craftsman auth:setup');
                }
                break;

            case 'auth':
                $this->line('');
                if ($this->confirm('Would you like to set up authentication now?', true)) {
                    $this->getApplication()->find('auth:setup')->run(
                        new \Symfony\Component\Console\Input\ArrayInput([]),
                        $this->output
                    );
                } else {
                    $this->note('You can set up authentication later with: php craftsman auth:setup');
                }
                break;

            case 'queue':
                $this->line('');
                $this->note('Queue module installed. Configure your queue driver in .env:');
                $this->line('  QUEUE_DRIVER=database|redis|sync');
                break;

            case 'cache':
                $this->line('');
                $this->note('Cache module installed. Configure your cache driver in .env:');
                $this->line('  CACHE_DRIVER=file|redis|memcached');
                break;

            case 'cms':
                $this->line('');
                $this->section('CMS Module Setup');
                if ($this->confirm('Would you like to set up the CMS tables now?', true)) {
                    $this->getApplication()->find('cms:setup')->run(
                        new \Symfony\Component\Console\Input\ArrayInput([]),
                        $this->output
                    );
                } else {
                    $this->note('You can set up the CMS later with: php craftsman cms:setup');
                }
                break;
        }
    }

    /**
     * Auto-install auth + authorization modules and run auth:setup
     */
    private function runAutoAuthSetup(): void
    {
        $configPath = $this->basePath('config/modules.php');

        // Install auth module
        $this->section('Installing Auth Module');
        $result = 0;
        passthru('composer require zephyrphp/auth', $result);

        if ($result !== 0) {
            $this->error('Failed to install auth module. You can try manually: php craftsman add auth');
            return;
        }

        $this->enableModuleInConfig('auth', $configPath);
        $this->success('Auth module installed and enabled.');

        // Install authorization module
        $this->section('Installing Authorization Module');
        $result = 0;
        passthru('composer require zephyrphp/authorization', $result);

        if ($result !== 0) {
            $this->warning('Failed to install authorization module. Continuing without roles support.');
        } else {
            $this->enableModuleInConfig('authorization', $configPath);
            $this->success('Authorization module installed and enabled.');
        }

        // Run auth:setup
        $this->line('');
        $this->section('Scaffolding Authentication System');
        $this->getApplication()->find('auth:setup')->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $this->output
        );
    }

    /**
     * Enable a module in config/modules.php
     */
    private function enableModuleInConfig(string $moduleName, string $configPath): void
    {
        if (!file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);

        if (preg_match("/['\"]" . preg_quote($moduleName, '/') . "['\"]\s*=>/", $content)) {
            $content = preg_replace(
                "/(['\"]" . preg_quote($moduleName, '/') . "['\"])\s*=>\s*false/",
                "$1 => true",
                $content
            );
        } else {
            $content = preg_replace(
                "/(\];?\s*)$/",
                "\n    // Added by: php craftsman add {$moduleName}\n    '{$moduleName}' => true,\n$1",
                $content
            );
        }

        file_put_contents($configPath, $content);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module name to add');
    }
}
