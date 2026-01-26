<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'key:generate',
    description: 'Generate a new application key'
)]
class KeyGenerateCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $key = 'base64:' . base64_encode(random_bytes(32));

        $envPath = $this->basePath('.env');
        $envExamplePath = $this->basePath('.env.example');

        if (file_exists($envPath)) {
            $this->updateEnvFile($envPath, $key);
            $this->success("Application key set: {$key}");
        } elseif (file_exists($envExamplePath)) {
            copy($envExamplePath, $envPath);
            $this->updateEnvFile($envPath, $key);
            $this->success("Created .env file and set application key: {$key}");
        } else {
            file_put_contents($envPath, "APP_KEY={$key}\n");
            $this->success("Created .env file with application key: {$key}");
        }

        return self::SUCCESS;
    }

    private function updateEnvFile(string $path, string $key): void
    {
        $content = file_get_contents($path);

        if (preg_match('/^APP_KEY=/m', $content)) {
            $content = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $content);
        } else {
            $content .= "\nAPP_KEY={$key}\n";
        }

        file_put_contents($path, $content);
    }
}
