<?php

declare(strict_types=1);

namespace ZephyrPHP\Console;

use Symfony\Component\Console\Application;

/**
 * Craftsman CLI Application
 *
 * The main entry point for the ZephyrPHP console.
 * Uses Symfony Console for robust CLI functionality with arrow key navigation.
 */
class Craftsman
{
    private Application $app;
    private string $version = '1.0.0';

    public function __construct()
    {
        $this->app = new Application('ZephyrPHP Craftsman', $this->version);
        $this->registerCommands();
    }

    /**
     * Register all commands from the Commands directory
     */
    protected function registerCommands(): void
    {
        $commandsDir = __DIR__ . '/Commands';
        $namespace = 'ZephyrPHP\\Console\\Commands\\';

        if (!is_dir($commandsDir)) {
            return;
        }

        $files = glob($commandsDir . '/*Command.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');

            // Skip the BaseCommand
            if ($className === 'BaseCommand') {
                continue;
            }

            $fullClassName = $namespace . $className;

            if (class_exists($fullClassName)) {
                $this->app->add(new $fullClassName());
            }
        }
    }

    /**
     * Run the application
     */
    public function run(array $argv = []): int
    {
        return $this->app->run();
    }

    /**
     * Get the Symfony Console Application instance
     */
    public function getApplication(): Application
    {
        return $this->app;
    }

    /**
     * Add a custom command to the application
     */
    public function addCommand($command): void
    {
        $this->app->add($command);
    }
}
