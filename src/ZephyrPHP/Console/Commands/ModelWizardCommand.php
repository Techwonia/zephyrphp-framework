<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'model:wizard',
    description: 'Interactive model builder wizard with arrow key navigation'
)]
class ModelWizardCommand extends BaseCommand
{
    private array $columnValidations = [];

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the model');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('ZephyrPHP Model Builder Wizard');
        $this->line('Use arrow keys to navigate, Enter to select');
        $this->line('');

        // Step 1: Model Name
        $name = $input->getArgument('name');
        if (empty($name)) {
            $name = $this->ask('Model name (e.g., User, Post, Product)');
            if (empty($name)) {
                $this->error('Model name is required');
                return self::FAILURE;
            }
        }

        $name = $this->sanitizeName($name);
        $this->io->text("Creating model: <info>{$name}</info>");

        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            if (!$this->confirm("Model '{$name}' already exists. Overwrite?", false)) {
                $this->line('Aborted.');
                return self::SUCCESS;
            }
        }

        // Create Blueprint
        $blueprint = \ZephyrPHP\Database\Builder\Blueprint::create($name);

        // Step 2: Table Name (smart pluralization)
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        // Don't add 's' if already ends with 's'
        $defaultTable = str_ends_with($tableName, 's') ? $tableName : $tableName . 's';
        $table = $this->ask("Table name", $defaultTable);
        $blueprint->table($table);

        // Step 3: Columns
        $this->section('Add Columns');

        $columnTypes = [
            'string'   => 'String (VARCHAR)',
            'text'     => 'Text (LONGTEXT)',
            'integer'  => 'Integer (INT)',
            'bigint'   => 'Big Integer (BIGINT)',
            'float'    => 'Float',
            'decimal'  => 'Decimal (DECIMAL)',
            'boolean'  => 'Boolean (TINYINT)',
            'datetime' => 'DateTime',
            'date'     => 'Date',
            'time'     => 'Time',
            'json'     => 'JSON',
            'guid'     => 'UUID/GUID',
        ];

        $columnCount = 0;
        while (true) {
            $columnCount++;
            $this->line("--- Column #{$columnCount} ---");
            $colName = $this->ask('Column name (press Enter to finish)', '');
            if (empty($colName)) {
                break;
            }

            // Column type - with arrow key selection
            $colType = $this->choice(
                "Select type for '{$colName}'",
                array_values($columnTypes),
                'String (VARCHAR)'
            );

            // Get the key from the value
            $colType = array_search($colType, $columnTypes);

            // Add column based on type
            switch ($colType) {
                case 'string':
                    $length = (int)$this->ask('Length', '255');
                    $blueprint->string($colName, $length);
                    break;
                case 'decimal':
                    $precision = (int)$this->ask('Precision', '10');
                    $scale = (int)$this->ask('Scale', '2');
                    $blueprint->decimal($colName, $precision, $scale);
                    break;
                default:
                    $blueprint->column($colName, $colType);
            }

            // Column modifiers
            $modifierOptions = [
                'Nullable - Allow NULL values',
                'Unique - Must be unique in table',
                'Indexed - Add database index',
                'Has Default - Set a default value',
            ];

            $selectedModifiers = $this->multiChoice(
                'Select column options (use arrow keys, space to select, enter to confirm)',
                $modifierOptions,
                []
            );

            if (in_array('Nullable - Allow NULL values', $selectedModifiers)) {
                $blueprint->nullable();
            }
            if (in_array('Unique - Must be unique in table', $selectedModifiers)) {
                $blueprint->unique();
            }
            if (in_array('Indexed - Add database index', $selectedModifiers)) {
                $blueprint->index();
            }
            if (in_array('Has Default - Set a default value', $selectedModifiers)) {
                $defaultVal = $this->ask('Default value');
                if ($colType === 'boolean') {
                    $defaultVal = in_array(strtolower($defaultVal), ['true', '1', 'yes']);
                } elseif (in_array($colType, ['integer', 'bigint', 'float'])) {
                    $defaultVal = (int)$defaultVal;
                }
                $blueprint->default($defaultVal);
            }

            // Validation rules for string/text types
            if (in_array($colType, ['string', 'text'])) {
                $this->addStringValidation($colName, $colType);
            } elseif (in_array($colType, ['integer', 'bigint', 'float', 'decimal'])) {
                $this->addNumericValidation($colName);
            } elseif (in_array($colType, ['date', 'datetime'])) {
                $this->addDateValidation($colName);
            }

            $this->io->success("Added: {$colName} ({$colType})");
            $this->line('');
        }

        // Step 4: Relationships
        if ($this->confirm('Add relationships?', false)) {
            $this->section('Add Relationships');

            $relationTypes = [
                'BelongsTo - Many-to-One (e.g., Post belongs to User)',
                'HasOne - One-to-One (e.g., User has one Profile)',
                'HasMany - One-to-Many (e.g., User has many Posts)',
                'BelongsToMany - Many-to-Many (e.g., Post has many Tags)',
            ];

            $relCount = 0;
            while (true) {
                $relCount++;
                $this->line("--- Relation #{$relCount} ---");
                $relProperty = $this->ask('Property name (press Enter to finish)', '');
                if (empty($relProperty)) {
                    break;
                }

                $relTypeChoice = $this->choice('Select relation type', $relationTypes, $relationTypes[0]);

                // Parse relation type
                $relType = match(true) {
                    str_starts_with($relTypeChoice, 'BelongsTo -') && !str_starts_with($relTypeChoice, 'BelongsToMany') => 'belongsTo',
                    str_starts_with($relTypeChoice, 'HasOne') => 'hasOne',
                    str_starts_with($relTypeChoice, 'HasMany') => 'hasMany',
                    str_starts_with($relTypeChoice, 'BelongsToMany') => 'belongsToMany',
                    default => 'belongsTo',
                };

                $targetModel = $this->ask('Target model (e.g., User)', ucfirst($relProperty));

                // Add relation
                match($relType) {
                    'belongsTo' => $blueprint->belongsTo($targetModel, $relProperty),
                    'hasOne' => $blueprint->hasOne($targetModel, $relProperty),
                    'hasMany' => $blueprint->hasMany($targetModel, $relProperty),
                    'belongsToMany' => $blueprint->belongsToMany($targetModel, $relProperty),
                };

                if ($this->confirm('Cascade persist/remove?', false)) {
                    $blueprint->cascade(['persist', 'remove']);
                }

                $this->io->success("Added: {$relProperty} ({$relType} -> {$targetModel})");
                $this->line('');
            }
        }

        // Step 5: Options
        $this->section('Model Options');

        $modelOptions = [
            'Timestamps - Add createdAt & updatedAt',
            'Soft Delete - Add deletedAt (trash instead of delete)',
        ];

        $selectedOptions = $this->multiChoice(
            'Select model options',
            $modelOptions,
            ['Timestamps - Add createdAt & updatedAt']
        );

        $timestamps = in_array('Timestamps - Add createdAt & updatedAt', $selectedOptions);
        $softDeletes = in_array('Soft Delete - Add deletedAt (trash instead of delete)', $selectedOptions);

        $blueprint->timestamps($timestamps);
        if ($softDeletes) {
            $blueprint->softDeletes(true);
        }

        // Store validations
        if (!empty($this->columnValidations)) {
            $blueprint->setValidations($this->columnValidations);
        }

        // Step 6: Preview & Generate
        $this->section('Model Summary');

        $summaryRows = [
            ['Model', $name],
            ['Table', $table],
            ['Timestamps', $timestamps ? 'Yes' : 'No'],
            ['Soft Delete', $softDeletes ? 'Yes' : 'No'],
        ];
        $this->table(['Property', 'Value'], $summaryRows);

        if (!empty($blueprint->getColumns())) {
            $columnRows = [];
            foreach ($blueprint->getColumns() as $col) {
                $mods = [];
                if ($col->isNullable()) $mods[] = 'nullable';
                if ($col->isUnique()) $mods[] = 'unique';
                if ($col->isIndexed()) $mods[] = 'indexed';
                $modStr = !empty($mods) ? implode(', ', $mods) : '-';

                $valStr = '-';
                if (isset($this->columnValidations[$col->getName()])) {
                    $valStr = implode(', ', $this->columnValidations[$col->getName()]);
                }

                $columnRows[] = [$col->getName(), $col->getType(), $modStr, $valStr];
            }
            $this->line('');
            $this->line('<comment>Columns:</comment>');
            $this->table(['Name', 'Type', 'Modifiers', 'Validations'], $columnRows);
        }

        if (!empty($blueprint->getRelations())) {
            $relationRows = [];
            foreach ($blueprint->getRelations() as $rel) {
                $relationRows[] = [$rel->getProperty(), $rel->getType(), $rel->getTarget()];
            }
            $this->line('');
            $this->line('<comment>Relations:</comment>');
            $this->table(['Property', 'Type', 'Target'], $relationRows);
        }

        $this->line('');
        if (!$this->confirm('Generate this model?', true)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        // Generate and save
        try {
            $content = $blueprint->build();
            if (file_put_contents($path, $content) !== false) {
                $this->line('');
                $this->success("Model created: app/Models/{$name}.php");
                $this->line('');
                $this->note('Next steps:');
                $this->line('  1. Run <info>php craftsman db:schema</info> to create/update tables');
                $this->line('  2. Customize the model as needed');
                $this->line('');
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

    /**
     * Add string validation rules
     */
    private function addStringValidation(string $colName, string $colType): void
    {
        $validationOptions = [
            'Required - Field cannot be empty',
            'Email - Must be valid email format',
            'URL - Must be valid URL format',
            'Phone - Phone number format',
            'Alpha - Only letters (a-z, A-Z)',
            'Alphanumeric - Letters and numbers only',
            'Slug - URL-safe slug (a-z, 0-9, -)',
            'IP Address - Valid IPv4 or IPv6',
            'JSON - Must be valid JSON string',
            'Custom Regex - Your own pattern',
        ];

        if ($this->confirm("Add validation rules for '{$colName}'?", false)) {
            $selectedValidations = $this->multiChoice(
                'Select validation rules',
                $validationOptions,
                []
            );

            $rules = [];
            foreach ($selectedValidations as $validation) {
                $rule = match(true) {
                    str_starts_with($validation, 'Required') => 'required',
                    str_starts_with($validation, 'Email') => 'email',
                    str_starts_with($validation, 'URL') => 'url',
                    str_starts_with($validation, 'Phone') => 'phone',
                    str_starts_with($validation, 'Alpha -') => 'alpha',
                    str_starts_with($validation, 'Alphanumeric') => 'alphanum',
                    str_starts_with($validation, 'Slug') => 'slug',
                    str_starts_with($validation, 'IP') => 'ip',
                    str_starts_with($validation, 'JSON') => 'json',
                    str_starts_with($validation, 'Custom') => null,
                    default => null,
                };

                if ($rule === null && str_starts_with($validation, 'Custom')) {
                    $pattern = $this->ask('Enter regex pattern (e.g., /^[A-Z]{2}[0-9]{4}$/)');
                    if (!empty($pattern)) {
                        $rule = "regex:{$pattern}";
                    }
                }

                if ($rule) {
                    $rules[] = $rule;
                }
            }

            // Length validation for strings
            if ($colType === 'string' && $this->confirm('Add length validation?', false)) {
                $minLen = $this->ask('Minimum length', '0');
                $maxLen = $this->ask('Maximum length', '255');
                if ((int)$minLen > 0) {
                    $rules[] = "min:{$minLen}";
                }
                if ((int)$maxLen < 255) {
                    $rules[] = "max:{$maxLen}";
                }
            }

            if (!empty($rules)) {
                $this->columnValidations[$colName] = $rules;
            }
        }
    }

    /**
     * Add numeric validation rules
     */
    private function addNumericValidation(string $colName): void
    {
        $validationOptions = [
            'Required - Field cannot be empty',
            'Positive - Must be > 0',
            'Negative - Must be < 0',
            'Range - Min/Max value',
        ];

        if ($this->confirm("Add validation rules for '{$colName}'?", false)) {
            $selectedValidations = $this->multiChoice(
                'Select validation rules',
                $validationOptions,
                []
            );

            $rules = [];
            foreach ($selectedValidations as $validation) {
                if (str_starts_with($validation, 'Required')) {
                    $rules[] = 'required';
                } elseif (str_starts_with($validation, 'Positive')) {
                    $rules[] = 'positive';
                } elseif (str_starts_with($validation, 'Negative')) {
                    $rules[] = 'negative';
                } elseif (str_starts_with($validation, 'Range')) {
                    $minVal = $this->ask('Minimum value', '');
                    $maxVal = $this->ask('Maximum value', '');
                    if ($minVal !== '') $rules[] = "min:{$minVal}";
                    if ($maxVal !== '') $rules[] = "max:{$maxVal}";
                }
            }

            if (!empty($rules)) {
                $this->columnValidations[$colName] = $rules;
            }
        }
    }

    /**
     * Add date validation rules
     */
    private function addDateValidation(string $colName): void
    {
        $validationOptions = [
            'Required - Field cannot be empty',
            'Past Date - Must be in the past',
            'Future Date - Must be in the future',
            'After Today - Must be today or later',
        ];

        if ($this->confirm("Add validation rules for '{$colName}'?", false)) {
            $selectedValidations = $this->multiChoice(
                'Select validation rules',
                $validationOptions,
                []
            );

            $rules = [];
            foreach ($selectedValidations as $validation) {
                $rule = match(true) {
                    str_starts_with($validation, 'Required') => 'required',
                    str_starts_with($validation, 'Past') => 'past',
                    str_starts_with($validation, 'Future') => 'future',
                    str_starts_with($validation, 'After') => 'after_today',
                    default => null,
                };

                if ($rule) {
                    $rules[] = $rule;
                }
            }

            if (!empty($rules)) {
                $this->columnValidations[$colName] = $rules;
            }
        }
    }
}
