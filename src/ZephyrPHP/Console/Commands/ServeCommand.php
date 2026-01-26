<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'serve',
    description: 'Start the development server'
)]
class ServeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::OPTIONAL, 'The host address', '127.0.0.1')
            ->addArgument('port', InputArgument::OPTIONAL, 'The port number', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $host = $input->getArgument('host');
        $port = $input->getArgument('port');
        $publicPath = $this->basePath('public');

        $this->line('');
        $this->info('ZephyrPHP Development Server');
        $this->line('');
        $this->line("Server running at: <href=http://{$host}:{$port}>http://{$host}:{$port}</>");
        $this->line('Press Ctrl+C to stop the server');
        $this->line('');

        passthru("php -S {$host}:{$port} -t \"{$publicPath}\"");

        return self::SUCCESS;
    }
}
