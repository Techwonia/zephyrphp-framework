<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:job',
    description: 'Create a new queue job class'
)]
class MakeJobCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $this->sanitizeName($input->getArgument('name'));
        if (!str_ends_with($name, 'Job')) {
            $name .= 'Job';
        }

        $dir = $this->basePath('app/Jobs');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->error("Job already exists: {$name}");
            return self::FAILURE;
        }

        $namespace = $this->getAppNamespace();
        $content = $this->getJobTemplate($namespace, $name);

        if (file_put_contents($path, $content) !== false) {
            $this->success("Job created: app/Jobs/{$name}.php");
            return self::SUCCESS;
        } else {
            $this->error("Failed to create job: {$name}");
            return self::FAILURE;
        }
    }

    private function getJobTemplate(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Jobs;

use ZephyrPHP\\Queue\\Job;

class {$name} extends Job
{
    public function __construct(
        // Add constructor parameters here
    ) {}

    public function handle(): void
    {
        // Job logic here
    }

    public function failed(\\Throwable \$e): void
    {
        // Handle job failure
    }
}
PHP;
    }
}
