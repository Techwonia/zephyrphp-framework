<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Put the application into maintenance mode.
 *
 * Usage:
 *   php craftsman down
 *   php craftsman down --message="Upgrading database"
 *   php craftsman down --retry=60
 *   php craftsman down --allow=127.0.0.1 --allow=192.168.1.100
 *   php craftsman down --secret=my-bypass-token
 */
#[AsCommand(
    name: 'down',
    description: 'Put the application into maintenance mode'
)]
class DownCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'The maintenance mode message', 'We are currently performing maintenance. Please check back soon.')
            ->addOption('retry', 'r', InputOption::VALUE_OPTIONAL, 'Retry-After header value in seconds')
            ->addOption('allow', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'IP addresses allowed to access the application')
            ->addOption('secret', 's', InputOption::VALUE_OPTIONAL, 'Secret phrase to bypass maintenance mode via URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $frameworkDir = $this->basePath('storage/framework');
        $this->ensureDirectory($frameworkDir);

        $downFile = $frameworkDir . '/down';

        $data = [
            'message' => $input->getOption('message'),
            'time' => date('Y-m-d H:i:s'),
        ];

        $retry = $input->getOption('retry');
        if ($retry !== null) {
            $data['retry'] = (int) $retry;
        }

        $allowed = $input->getOption('allow');
        if (!empty($allowed)) {
            $data['allowed'] = array_filter($allowed);
        }

        $secret = $input->getOption('secret');
        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($downFile, $json, LOCK_EX);

        $this->success('Application is now in maintenance mode.');

        if ($secret !== null) {
            $this->info("Bypass URL: {your-app-url}?bypass={$secret}");
        }

        if (!empty($allowed)) {
            $this->info('Allowed IPs: ' . implode(', ', array_filter($allowed)));
        }

        return self::SUCCESS;
    }
}
