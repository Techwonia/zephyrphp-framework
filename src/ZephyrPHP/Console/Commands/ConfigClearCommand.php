<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZephyrPHP\Config\Config;

/**
 * Remove the configuration cache file.
 *
 * Usage:
 *   php craftsman config:clear
 */
#[AsCommand(
    name: 'config:clear',
    description: 'Remove the configuration cache file'
)]
class ConfigClearCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        if (Config::clearCache()) {
            $this->success('Configuration cache cleared.');
            return self::SUCCESS;
        }

        $this->error('Failed to clear configuration cache.');
        return self::FAILURE;
    }
}
