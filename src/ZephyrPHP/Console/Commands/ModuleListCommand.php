<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:list',
    description: 'List all available modules and their status'
)]
class ModuleListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('Available Modules');

        $modulesPath = $this->basePath('modules');
        $configPath = $this->basePath('config/modules.php');

        $enabledModules = [];
        if (file_exists($configPath)) {
            $enabledModules = include $configPath;
        }

        // Scan for installed packages that are modules
        $composerPath = $this->basePath('composer.json');
        $modules = [];

        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $packages = $composer['require'] ?? [];

            foreach ($packages as $package => $version) {
                if (str_starts_with($package, 'zephyrphp/') && $package !== 'zephyrphp/framework') {
                    $moduleName = str_replace('zephyrphp/', '', $package);
                    $modules[] = [
                        $moduleName,
                        $version,
                        in_array($moduleName, $enabledModules) ? 'Enabled' : 'Disabled',
                    ];
                }
            }
        }

        // Check local modules directory
        if (is_dir($modulesPath)) {
            $localModules = glob($modulesPath . '/*/module.json');
            foreach ($localModules as $moduleFile) {
                $moduleInfo = json_decode(file_get_contents($moduleFile), true);
                $moduleName = $moduleInfo['name'] ?? basename(dirname($moduleFile));
                $version = $moduleInfo['version'] ?? 'local';

                // Check if not already listed
                $exists = false;
                foreach ($modules as $m) {
                    if ($m[0] === $moduleName) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $modules[] = [
                        $moduleName,
                        $version,
                        in_array($moduleName, $enabledModules) ? 'Enabled' : 'Disabled',
                    ];
                }
            }
        }

        if (empty($modules)) {
            $this->info('No modules installed.');
            $this->line('');
            $this->line('Install modules with: php craftsman add <module-name>');
            return self::SUCCESS;
        }

        $this->table(['Module', 'Version', 'Status'], $modules);

        return self::SUCCESS;
    }
}
