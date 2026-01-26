<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'remove',
    description: 'Remove a module package from the project'
)]
class RemoveModuleCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module name to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $moduleName = $input->getArgument('module');

        if (!$this->confirm("Are you sure you want to remove module '{$moduleName}'?", false)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $this->line("Removing module: {$moduleName}");
        $this->line('');

        // Determine package name (use zephyrphp vendor prefix)
        $packageName = str_contains($moduleName, '/') ? $moduleName : "zephyrphp/{$moduleName}";

        // Disable the module first
        $shortName = str_contains($moduleName, '/') ? explode('/', $moduleName)[1] : $moduleName;
        $configPath = $this->basePath('config/modules.php');

        if (file_exists($configPath)) {
            $enabledModules = include $configPath;
            $enabledModules = array_filter($enabledModules, fn($m) => $m !== $shortName);
            $enabledModules = array_values($enabledModules);
            $content = "<?php\n\nreturn " . var_export($enabledModules, true) . ";\n";
            file_put_contents($configPath, $content);
        }

        // Run composer remove
        $this->line("Running: composer remove {$packageName}");
        $this->line('');

        $result = 0;
        passthru("composer remove {$packageName}", $result);

        if ($result !== 0) {
            $this->error("Failed to remove module: {$moduleName}");
            return self::FAILURE;
        }

        $this->line('');
        $this->success("Module '{$moduleName}' has been removed.");

        return self::SUCCESS;
    }
}
