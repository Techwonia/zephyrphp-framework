<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZephyrPHP\Config\Config;

/**
 * Cache the configuration files into a single file.
 *
 * Usage:
 *   php craftsman config:cache
 */
#[AsCommand(
    name: 'config:cache',
    description: 'Create a configuration cache file for faster loading'
)]
class ConfigCacheCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        // Clear any existing cache first
        Config::clearCache();

        // Load fresh config from files
        Config::reset();
        Config::load($this->basePath('config'));

        // Write cache
        if (Config::cache()) {
            $this->success('Configuration cached successfully.');
            return self::SUCCESS;
        }

        $this->error('Failed to cache configuration.');
        return self::FAILURE;
    }
}
