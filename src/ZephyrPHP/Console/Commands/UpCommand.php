<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bring the application out of maintenance mode.
 *
 * Usage:
 *   php craftsman up
 */
#[AsCommand(
    name: 'up',
    description: 'Bring the application out of maintenance mode'
)]
class UpCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $downFile = $this->basePath('storage/framework/down');

        if (!file_exists($downFile)) {
            $this->info('Application is already live.');
            return self::SUCCESS;
        }

        unlink($downFile);

        $this->success('Application is now live.');

        return self::SUCCESS;
    }
}
