<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:enable',
    description: 'Enable a module'
)]
class ModuleEnableCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('module', InputArgument::REQUIRED, 'The module name to enable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $moduleName = $input->getArgument('module');
        $configPath = $this->basePath('config/modules.php');
        $configDir = dirname($configPath);

        $this->ensureDirectory($configDir);

        $enabledModules = [];
        if (file_exists($configPath)) {
            $enabledModules = include $configPath;
        }

        if (in_array($moduleName, $enabledModules)) {
            $this->info("Module '{$moduleName}' is already enabled.");
            return self::SUCCESS;
        }

        $enabledModules[] = $moduleName;

        $content = "<?php\n\nreturn " . var_export($enabledModules, true) . ";\n";
        file_put_contents($configPath, $content);

        $this->success("Module '{$moduleName}' has been enabled.");

        return self::SUCCESS;
    }
}
