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
            ->addArgument('host', InputArgument::OPTIONAL, 'The host address', 'localhost')
            ->addArgument('port', InputArgument::OPTIONAL, 'The port number', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $host = $input->getArgument('host');
        $port = $input->getArgument('port');
        $publicPath = $this->basePath('public');

        // Create a router script so the built-in server passes
        // non-static-file requests through to the framework
        $routerPath = $this->basePath('storage/.server-router.php');
        $routerCode = <<<'ROUTER'
<?php
// PHP built-in server router script
// Serve existing static files directly, route everything else to index.php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicPath = $_SERVER['DOCUMENT_ROOT'];

// If the file exists in public/, let the built-in server handle it
if ($uri !== '/' && file_exists($publicPath . $uri)) {
    return false;
}

// Otherwise, route through the framework
require $publicPath . '/index.php';
ROUTER;
        $storageDir = dirname($routerPath);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        file_put_contents($routerPath, $routerCode);

        $this->line('');
        $this->info('ZephyrPHP Development Server');
        $this->line('');
        $this->line("Server running at: <href=http://{$host}:{$port}>http://{$host}:{$port}</>");
        $this->line('Press Ctrl+C to stop the server');
        $this->line('');

        passthru("php -S {$host}:{$port} -t \"{$publicPath}\" \"{$routerPath}\"");

        return self::SUCCESS;
    }
}
