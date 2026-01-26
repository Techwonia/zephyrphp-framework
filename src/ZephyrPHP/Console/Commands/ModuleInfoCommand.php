<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:info',
    description: 'Show detailed information about a module'
)]
class ModuleInfoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $moduleName = $input->getArgument('module');

        // Check in vendor
        $vendorPath = $this->basePath("vendor/zephyrphp/{$moduleName}/module.json");
        $localPath = $this->basePath("modules/{$moduleName}/module.json");

        $moduleInfo = null;
        $location = 'unknown';

        if (file_exists($vendorPath)) {
            $moduleInfo = json_decode(file_get_contents($vendorPath), true);
            $location = 'vendor';
        } elseif (file_exists($localPath)) {
            $moduleInfo = json_decode(file_get_contents($localPath), true);
            $location = 'local';
        }

        if (!$moduleInfo) {
            $this->error("Module '{$moduleName}' not found.");
            return self::FAILURE;
        }

        // Check if enabled
        $configPath = $this->basePath('config/modules.php');
        $enabledModules = file_exists($configPath) ? include $configPath : [];
        $isEnabled = in_array($moduleName, $enabledModules);

        $this->title("Module: {$moduleName}");

        $rows = [
            ['Name', $moduleInfo['name'] ?? $moduleName],
            ['Version', $moduleInfo['version'] ?? 'N/A'],
            ['Description', $moduleInfo['description'] ?? 'N/A'],
            ['Author', $moduleInfo['author'] ?? 'N/A'],
            ['License', $moduleInfo['license'] ?? 'N/A'],
            ['Location', $location],
            ['Status', $isEnabled ? 'Enabled' : 'Disabled'],
        ];

        $this->table(['Property', 'Value'], $rows);

        // Show dependencies
        if (!empty($moduleInfo['require'])) {
            $this->section('Dependencies');
            foreach ($moduleInfo['require'] as $dep => $version) {
                $this->line("  - {$dep}: {$version}");
            }
        }

        // Show provided features
        if (!empty($moduleInfo['provides'])) {
            $this->section('Provides');
            foreach ($moduleInfo['provides'] as $feature) {
                $this->line("  - {$feature}");
            }
        }

        return self::SUCCESS;
    }
}
