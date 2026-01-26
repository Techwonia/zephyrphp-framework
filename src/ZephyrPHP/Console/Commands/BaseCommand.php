<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base command class with helper methods
 *
 * Uses Symfony Console SymfonyStyle for cross-platform CLI support
 */
abstract class BaseCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;
    protected SymfonyStyle $io;

    /**
     * Initialize the command with input/output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * Display a success message
     */
    protected function success(string $message): void
    {
        $this->io->success($message);
    }

    /**
     * Display an error message
     */
    protected function error(string $message): void
    {
        $this->io->error($message);
    }

    /**
     * Display an info message
     */
    protected function info(string $message): void
    {
        $this->io->info($message);
    }

    /**
     * Display a warning message
     */
    protected function warning(string $message): void
    {
        $this->io->warning($message);
    }

    /**
     * Display a line of text
     */
    protected function line(string $message = ''): void
    {
        $this->output->writeln($message);
    }

    /**
     * Ask a question with text input
     */
    protected function ask(string $question, string $default = ''): string
    {
        return $this->io->ask($question, $default) ?? $default;
    }

    /**
     * Ask a hidden question (for passwords)
     */
    protected function askHidden(string $question): string
    {
        return $this->io->askHidden($question) ?? '';
    }

    /**
     * Ask for confirmation
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io->confirm($question, $default);
    }

    /**
     * Display a choice menu with arrow key navigation
     */
    protected function choice(string $question, array $choices, $default = null): string
    {
        $choices = array_values($choices);
        $defaultValue = $default ?? $choices[0] ?? null;

        return $this->io->choice($question, $choices, $defaultValue);
    }

    /**
     * Display a multi-choice menu
     */
    protected function multiChoice(string $question, array $choices, array $default = []): array
    {
        $choices = array_values($choices);

        // SymfonyStyle doesn't have multiselect, so we use choice with a note
        $this->note('Select multiple by entering comma-separated numbers (e.g., 0,1,2)');

        $selected = [];
        foreach ($choices as $index => $choice) {
            if ($this->confirm("  Include '{$choice}'?", in_array($choice, $default))) {
                $selected[] = $choice;
            }
        }

        return $selected;
    }

    /**
     * Display a table
     */
    protected function table(array $headers, array $rows): void
    {
        $this->io->table($headers, $rows);
    }

    /**
     * Display a section header
     */
    protected function section(string $title): void
    {
        $this->io->section($title);
    }

    /**
     * Display a title
     */
    protected function title(string $title): void
    {
        $this->io->title($title);
    }

    /**
     * Create a progress bar
     */
    protected function progressStart(int $max = 0): void
    {
        $this->io->progressStart($max);
    }

    /**
     * Advance the progress bar
     */
    protected function progressAdvance(int $step = 1): void
    {
        $this->io->progressAdvance($step);
    }

    /**
     * Finish the progress bar
     */
    protected function progressFinish(): void
    {
        $this->io->progressFinish();
    }

    /**
     * Display a note
     */
    protected function note(string $message): void
    {
        $this->io->note($message);
    }

    /**
     * Display a caution message
     */
    protected function caution(string $message): void
    {
        $this->io->caution($message);
    }

    /**
     * Get the base path of the application
     */
    protected function basePath(string $path = ''): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $path ? $base . DIRECTORY_SEPARATOR . $path : $base;
    }

    /**
     * Ensure a directory exists
     */
    protected function ensureDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }

    /**
     * Sanitize a name (PascalCase)
     */
    protected function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9]/', ' ', $name);
        $name = ucwords($name);
        return str_replace(' ', '', $name);
    }

    /**
     * Get the app namespace from composer.json
     */
    protected function getAppNamespace(): string
    {
        $composerPath = $this->basePath('composer.json');
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            if (isset($composer['autoload']['psr-4'])) {
                foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                    if (str_contains($path, 'app')) {
                        return rtrim($namespace, '\\');
                    }
                }
            }
        }
        return 'App';
    }
}
