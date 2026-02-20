<?php

namespace ZephyrPHP\Console;

class Installer
{
    private const RESERVED_WORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
        'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset',
        'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'return', 'static', 'switch', 'throw',
        'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield'
    ];

    /**
     * Post create project setup - can be called with or without Composer Event
     */
    public static function postCreateProject($event = null): void
    {
        // Check if called with Composer Event or standalone
        $io = $event ? new ComposerIO($event->getIO()) : new ConsoleIO();

        $io->write('');
        $io->write('ZephyrPHP - Light as a breeze, fast as the wind.');
        $io->write('');

        $projectDir = getcwd();
        $defaultName = self::sanitizeName(basename($projectDir));

        // Ask for namespace with validation loop
        $namespace = self::askNamespace($io, $defaultName);

        if ($namespace !== 'App') {
            self::updateNamespace($projectDir, $namespace);
            $io->success("Namespace set to: {$namespace}");

            // Regenerate autoloader with the new namespace
            self::dumpAutoload($projectDir, $io);
        }

        // Generate APP_KEY
        self::generateAppKey($projectDir);
        $io->success('Application key generated.');

        $io->write('');
        $io->write('Setup complete! Run: php craftsman serve');
        $io->write('');
    }

    private static function askNamespace($io, string $default): string
    {
        while (true) {
            $input = $io->ask("Enter your project namespace [{$default}]: ", $default);

            $result = self::validateNamespace($input);

            if ($result['valid']) {
                return $result['namespace'];
            }

            // Show error and re-prompt
            $io->error("Error: {$result['error']}");
            $io->write('Please enter a valid namespace (letters and underscores only)');
            $io->write('');
        }
    }

    private static function validateNamespace(string $name): array
    {
        $original = $name;
        $name = trim($name);

        // Empty check
        if ($name === '') {
            return [
                'valid' => false,
                'error' => 'Namespace cannot be empty.',
                'namespace' => null
            ];
        }

        // Check for numbers
        if (preg_match('/[0-9]/', $name)) {
            return [
                'valid' => false,
                'error' => "'{$original}' contains numbers. PHP namespaces cannot contain numbers.",
                'namespace' => null
            ];
        }

        // Sanitize: keep only letters, underscores and spaces
        $sanitized = preg_replace('/[^a-zA-Z_\s]/', '', $name);

        if ($sanitized === '' || $sanitized === '_') {
            return [
                'valid' => false,
                'error' => "'{$original}' contains no valid characters. Use letters and underscores.",
                'namespace' => null
            ];
        }

        // Convert spaces to nothing, apply PascalCase to words
        $namespace = str_replace(' ', '', ucwords(strtolower($sanitized), " _"));

        // Cannot start with underscore
        if (str_starts_with($namespace, '_')) {
            return [
                'valid' => false,
                'error' => "Namespace cannot start with an underscore.",
                'namespace' => null
            ];
        }

        // Check reserved words
        if (in_array(strtolower($namespace), self::RESERVED_WORDS)) {
            return [
                'valid' => false,
                'error' => "'{$namespace}' is a PHP reserved word.",
                'namespace' => null
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'namespace' => $namespace
        ];
    }

    private static function sanitizeName(string $name): string
    {
        // Remove numbers and special chars, keep letters and underscores
        $name = preg_replace('/[^a-zA-Z_\s]/', '', $name);
        $name = str_replace(' ', '', ucwords(strtolower($name), " _"));
        $name = ltrim($name, '_');

        if ($name === '' || in_array(strtolower($name), self::RESERVED_WORDS)) {
            return 'App';
        }

        return $name;
    }

    private static function updateNamespace(string $dir, string $namespace): void
    {
        // Update composer.json using JSON to avoid escaping issues
        $composerFile = $dir . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);

            // Update PSR-4 autoload - remove App and add new namespace
            if (isset($composer['autoload']['psr-4']['App\\'])) {
                unset($composer['autoload']['psr-4']['App\\']);
                $composer['autoload']['psr-4'][$namespace . '\\'] = 'app/';
            }

            file_put_contents(
                $composerFile,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
        }

        // Update PHP files in app/ and routes/ recursively
        $dirsToUpdate = ['app', 'routes'];

        foreach ($dirsToUpdate as $directory) {
            $fullPath = $dir . '/' . $directory;

            if (is_dir($fullPath)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->getExtension() === 'php') {
                        $content = file_get_contents($file->getPathname());
                        // Replace App namespace with new namespace
                        $content = str_replace('namespace App\\', 'namespace ' . $namespace . '\\', $content);
                        $content = str_replace('namespace App;', 'namespace ' . $namespace . ';', $content);
                        $content = str_replace('use App\\', 'use ' . $namespace . '\\', $content);
                        file_put_contents($file->getPathname(), $content);
                    }
                }
            }
        }
    }

    private static function dumpAutoload(string $dir, $io): void
    {
        $prevDir = getcwd();
        chdir($dir);
        exec('composer dump-autoload --quiet 2>&1', $output, $exitCode);
        chdir($prevDir);

        if ($exitCode !== 0) {
            $io->error('Could not regenerate autoloader. Run "composer dump-autoload" manually.');
        }
    }

    private static function generateAppKey(string $dir): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        $envFile = $dir . '/.env';
        $envExample = $dir . '/.env.example';

        // Copy .env.example to .env if not exists
        if (!file_exists($envFile) && file_exists($envExample)) {
            copy($envExample, $envFile);
        }

        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content);
            file_put_contents($envFile, $content);
        }
    }
}

/**
 * Console IO - Simple stdin/stdout based IO
 */
class ConsoleIO
{
    public function write(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function success(string $message): void
    {
        echo "\033[32m✓ {$message}\033[0m" . PHP_EOL;
    }

    public function error(string $message): void
    {
        echo "\033[31m✗ {$message}\033[0m" . PHP_EOL;
    }

    public function ask(string $question, string $default = ''): string
    {
        echo $question;
        $input = trim(fgets(STDIN));
        return $input === '' ? $default : $input;
    }
}

/**
 * Composer IO - Wrapper around Composer's IOInterface
 */
class ComposerIO
{
    private $io;

    public function __construct($io)
    {
        $this->io = $io;
    }

    public function write(string $message): void
    {
        $this->io->write($message);
    }

    public function success(string $message): void
    {
        $this->io->write("<info>✓ {$message}</info>");
    }

    public function error(string $message): void
    {
        $this->io->write("<error>✗ {$message}</error>");
    }

    public function ask(string $question, string $default = ''): string
    {
        return $this->io->ask($question, $default);
    }
}
