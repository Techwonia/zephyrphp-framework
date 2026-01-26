<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:migration',
    description: 'Create a new database migration'
)]
class MakeMigrationCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $input->getArgument('name');
        $timestamp = date('Y_m_d_His');
        $className = $this->sanitizeName($name);
        $fileName = "{$timestamp}_{$name}";

        $dir = $this->basePath('database/migrations');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$fileName}.php";

        $content = $this->getMigrationTemplate($className);

        if (file_put_contents($path, $content) !== false) {
            $this->success("Migration created: database/migrations/{$fileName}.php");
            return self::SUCCESS;
        } else {
            $this->error("Failed to create migration: {$fileName}");
            return self::FAILURE;
        }
    }

    private function getMigrationTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use ZephyrPHP\\Database\\Migration;
use ZephyrPHP\\Database\\Schema\\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        \$this->schema->create('table_name', function (Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        \$this->schema->dropIfExists('table_name');
    }
};
PHP;
    }
}
