<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:model',
    description: 'Create a new Doctrine entity model'
)]
class MakeModelCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $this->sanitizeName($input->getArgument('name'));

        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->error("Model already exists: {$name}");
            return self::FAILURE;
        }

        $namespace = $this->detectNamespace();
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
        $content = $this->generateModel($namespace, $name, $table);

        if (file_put_contents($path, $content) !== false) {
            $this->success("Model created: app/Models/{$name}.php");
            $this->note('Use "php craftsman model:wizard" for interactive model building with validation rules.');
            return self::SUCCESS;
        }

        $this->error("Failed to create model: {$name}");
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
                        return rtrim($ns, '\\') . '\\Models';
                    }
                }
            }
        }
        return 'App\\Models';
    }

    private function generateModel(string $namespace, string $name, string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: '{$table}')]
#[ORM\HasLifecycleCallbacks]
class {$name} extends Model
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int \$id = null;

    public function getId(): ?int
    {
        return \$this->id;
    }
}
PHP;
    }
}
