<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'model:build',
    description: 'Build a model with fluent Blueprint API from command line'
)]
class ModelBuildCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model')
            ->addArgument('definitions', InputArgument::IS_ARRAY, 'Column/relation definitions (e.g., name:string email:string:unique)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $name = $this->sanitizeName($input->getArgument('name'));
        $definitions = $input->getArgument('definitions');

        if (empty($definitions)) {
            $this->line('');
            $this->info('Model Builder - Quick Definition Syntax');
            $this->line('');
            $this->line('Usage: php craftsman model:build ModelName "field1:type:modifiers" ...');
            $this->line('');
            $this->line('<comment>Column Types:</comment>');
            $this->line('  s, str, string    - String (VARCHAR)');
            $this->line('  t, text          - Text (LONGTEXT)');
            $this->line('  i, int, integer  - Integer');
            $this->line('  bi, bigint       - Big Integer');
            $this->line('  b, bool, boolean - Boolean');
            $this->line('  f, float         - Float');
            $this->line('  d, decimal       - Decimal (use 10,2 for precision)');
            $this->line('  dt, datetime     - DateTime');
            $this->line('  date             - Date');
            $this->line('  time             - Time');
            $this->line('  j, json          - JSON');
            $this->line('  g, guid, uuid    - UUID/GUID');
            $this->line('');
            $this->line('<comment>Modifiers:</comment>');
            $this->line('  nullable, unique, index, unsigned, default:value');
            $this->line('');
            $this->line('<comment>Relations:</comment>');
            $this->line('  belongsTo:Target, hasOne:Target, hasMany:Target, belongsToMany:Target');
            $this->line('');
            $this->line('<comment>Examples:</comment>');
            $this->line('  php craftsman model:build Post title:string body:text user:belongsTo:User');
            $this->line('  php craftsman model:build User name:string email:string:unique posts:hasMany:Post');
            $this->line('  php craftsman model:build Product name:string price:decimal:10,2 stock:integer:default:0');
            return self::SUCCESS;
        }

        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->error("Model already exists: {$name}");
            return self::FAILURE;
        }

        // Use the Blueprint builder
        $wizard = new \ZephyrPHP\Database\Builder\ModelWizard($name, false);

        // Parse all definitions
        foreach ($definitions as $def) {
            if (!empty($def)) {
                $wizard->parseQuickDefinition($def);
            }
        }

        // Generate and save
        try {
            $content = $wizard->getBlueprint()->build();
            if (file_put_contents($path, $content) !== false) {
                $this->success("Model built: app/Models/{$name}.php");
                $this->line('');
                $this->line('<comment>Columns added:</comment>');
                foreach ($wizard->getBlueprint()->getColumns() as $col) {
                    $this->line("  - {$col->getName()} ({$col->getType()})");
                }
                if (!empty($wizard->getBlueprint()->getRelations())) {
                    $this->line('');
                    $this->line('<comment>Relations added:</comment>');
                    foreach ($wizard->getBlueprint()->getRelations() as $rel) {
                        $this->line("  - {$rel->getProperty()} ({$rel->getType()} -> {$rel->getTarget()})");
                    }
                }
                $this->line('');
                $this->note('Run "php craftsman db:schema" to update the database.');
                return self::SUCCESS;
            } else {
                $this->error("Failed to create model: {$name}");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error building model: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
