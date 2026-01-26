<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:controller',
    description: 'Create a new controller class'
)]
class MakeControllerCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $this->sanitizeName($input->getArgument('name'));
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $dir = $this->basePath('app/Controllers');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->error("Controller already exists: {$name}");
            return self::FAILURE;
        }

        $namespace = $this->detectNamespace();
        $content = $this->generateController($namespace, $name);

        if (file_put_contents($path, $content) !== false) {
            $this->success("Controller created: app/Controllers/{$name}.php");
            return self::SUCCESS;
        }

        $this->error("Failed to create controller: {$name}");
        return self::FAILURE;
    }

    private function detectNamespace(): string
    {
        $composerFile = $this->basePath('composer.json');
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            if (isset($composer['autoload']['psr-4'])) {
                foreach ($composer['autoload']['psr-4'] as $ns => $path) {
                    if ($path === 'app/' || $path === 'app') {
                        return rtrim($ns, '\\') . '\\Controllers';
                    }
                }
            }
        }
        return 'App\\Controllers';
    }

    private function generateController(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use ZephyrPHP\Http\Request;
use ZephyrPHP\Http\Response;

class {$name}
{
    public function index(Request \$request): Response
    {
        return response()->json(['message' => 'Hello from {$name}']);
    }
}
PHP;
    }
}
