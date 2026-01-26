<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cache:clear',
    description: 'Clear all cached files'
)]
class CacheClearCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $cacheDir = $this->basePath('storage/cache');
        $viewsCache = $this->basePath('storage/views');

        $cleared = 0;

        if (is_dir($cacheDir)) {
            $cleared += $this->clearDirectory($cacheDir);
        }

        if (is_dir($viewsCache)) {
            $cleared += $this->clearDirectory($viewsCache);
        }

        $this->success("Cache cleared! Removed {$cleared} files.");
        return self::SUCCESS;
    }

    private function clearDirectory(string $dir): int
    {
        $count = 0;
        $files = glob($dir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            } elseif (is_dir($file)) {
                $count += $this->clearDirectory($file);
                rmdir($file);
            }
        }

        return $count;
    }
}
