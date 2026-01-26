<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:middleware',
    description: 'Create a new middleware class'
)]
class MakeMiddlewareCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the middleware');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $this->sanitizeName($input->getArgument('name'));
        if (!str_ends_with($name, 'Middleware')) {
            $name .= 'Middleware';
        }

        $dir = $this->basePath('app/Middleware');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->error("Middleware already exists: {$name}");
            return self::FAILURE;
        }

        $namespace = $this->getAppNamespace();
        $content = $this->getMiddlewareTemplate($namespace, $name);

        if (file_put_contents($path, $content) !== false) {
            $this->success("Middleware created: app/Middleware/{$name}.php");
            return self::SUCCESS;
        } else {
            $this->error("Failed to create middleware: {$name}");
            return self::FAILURE;
        }
    }

    private function getMiddlewareTemplate(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Middleware;

use Psr\\Http\\Message\\ResponseInterface;
use Psr\\Http\\Message\\ServerRequestInterface;
use Psr\\Http\\Server\\MiddlewareInterface;
use Psr\\Http\\Server\\RequestHandlerInterface;

class {$name} implements MiddlewareInterface
{
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // Before handling the request

        \$response = \$handler->handle(\$request);

        // After handling the request

        return \$response;
    }
}
PHP;
    }
}
