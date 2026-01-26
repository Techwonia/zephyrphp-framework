<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:crud',
    description: 'Create complete CRUD (Model, Controller, Views, Routes)'
)]
class MakeCrudCommand extends BaseCommand
{
    private string $modelName;
    private string $modelClass;
    private array $modelFields = [];
    private bool $modelHasValidation = false;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the resource (e.g., Post, Product, User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        // Check if database module is enabled
        if (!$this->isDatabaseModuleEnabled()) {
            $this->warning('Database module is not enabled.');
            $this->line('');
            $this->line('CRUD functionality requires the database module.');
            $this->line('');

            if ($this->confirm('Would you like to enable the database module now?', true)) {
                $this->enableDatabaseModule();
                $this->success('Database module enabled!');
                $this->line('');
            } else {
                $this->line('');
                $this->note('To enable manually:');
                $this->line('  1. Run: php craftsman add database');
                $this->line('  2. Set "database" => true in config/modules.php');
                $this->line('');
                return self::FAILURE;
            }
        }

        $rawName = $input->getArgument('name');

        // If no name provided, show interactive menu
        if (empty($rawName)) {
            $rawName = $this->interactiveModelSelection();
            if ($rawName === null) {
                return self::SUCCESS; // User cancelled
            }
        }

        // Convert to singular PascalCase (Users -> User, posts -> Post)
        $name = $this->toSingular($this->sanitizeName($rawName));
        $nameLower = strtolower($name);
        $namePlural = $this->toPlural($nameLower);

        $this->title("Creating CRUD for: {$name}");

        $namespace = $this->getAppNamespace();

        // Check for existing model (singular, plural, various cases)
        $this->section('Checking for existing Model');
        $existingModel = $this->findExistingModel($name);

        if ($existingModel) {
            $this->modelName = $existingModel['name'];
            $this->modelClass = $existingModel['class'];
            $this->success("Found existing model: {$this->modelName}");
            $this->parseModelFields($existingModel['path']);
        } else {
            // Ask if user wants to use the wizard or create a simple model
            $useWizard = $this->confirm('Model not found. Would you like to use the Model Wizard to create it?', true);

            if ($useWizard) {
                // Run model:wizard command
                $wizardResult = $this->runModelWizard($name);
                if ($wizardResult !== self::SUCCESS) {
                    return $wizardResult;
                }

                // Re-check for the model after wizard
                $existingModel = $this->findExistingModel($name);
                if ($existingModel) {
                    $this->modelName = $existingModel['name'];
                    $this->modelClass = $existingModel['class'];
                    $this->parseModelFields($existingModel['path']);
                } else {
                    $this->error('Model was not created. Aborting CRUD generation.');
                    return self::FAILURE;
                }
            } else {
                // Create simple model
                $this->section('Creating Model');
                $this->modelName = $name;
                $this->modelClass = "{$namespace}\\Models\\{$name}";
                $this->createModel($name, $namespace);
                // Set default fields for new model
                $this->modelFields = [
                    'name' => ['type' => 'string', 'nullable' => false],
                ];
                $this->modelHasValidation = true;
            }
        }

        // Create Controller
        $this->section('Creating Controller');
        $this->createController($this->modelName, $namespace, $nameLower, $namePlural);

        // Create Views
        $this->section('Creating Views');
        $this->createViews($this->modelName, $nameLower, $namePlural);

        // Add Routes
        $this->section('Adding Routes');
        $routesAdded = $this->addRoutes($this->modelName, $namePlural, $namespace);

        $this->line('');
        $this->success("CRUD for '{$this->modelName}' created successfully!");

        $this->line('');
        $this->note('Next steps:');
        if (!$routesAdded) {
            $this->line('  1. Add the routes manually to routes/web.php');
            $this->line('  2. Run "php craftsman db:schema" to create the table');
        } else {
            $this->line('  1. Run "php craftsman db:schema" to create the table');
        }
        $this->line('  - Customize the model, controller, and views as needed');
        $this->line('');
        $this->info("Try it: php craftsman serve, then visit http://localhost:8000/{$namePlural}");

        return self::SUCCESS;
    }

    /**
     * Interactive model selection when no name is provided
     */
    private function interactiveModelSelection(): ?string
    {
        $this->title('CRUD Generator');
        $this->line('');

        // Get list of existing models
        $models = $this->getAllModels();

        if (empty($models)) {
            // No existing models - ask for new model name
            $this->info('No existing models found in app/Models/');
            $this->line('');

            $name = $this->ask('Enter the name for your new model (e.g., User, Post, Product)');
            if (empty($name)) {
                $this->warning('No name provided. Aborted.');
                return null;
            }

            return $name;
        }

        // Show existing models + option to create new
        $this->section('Available Models');

        $choices = [];
        foreach ($models as $model) {
            $choices[] = $model['name'] . ' (existing)';
        }
        $choices[] = '+ Create new model';

        $selected = $this->choice(
            'Select a model to generate CRUD for',
            $choices,
            $choices[0]
        );

        // Check if user wants to create new
        if ($selected === '+ Create new model') {
            $name = $this->ask('Enter the name for your new model (e.g., User, Post, Product)');
            if (empty($name)) {
                $this->warning('No name provided. Aborted.');
                return null;
            }
            return $name;
        }

        // Extract model name from selection (remove " (existing)" suffix)
        $modelName = str_replace(' (existing)', '', $selected);

        // Check if CRUD already exists for this model
        $namePlural = $this->toPlural(strtolower($modelName));
        $controllerPath = $this->basePath("app/Controllers/{$modelName}Controller.php");

        if (file_exists($controllerPath)) {
            $this->warning("Controller already exists for {$modelName}.");
            if (!$this->confirm('Do you want to overwrite existing CRUD files?', false)) {
                $this->line('Aborted.');
                return null;
            }
        }

        return $modelName;
    }

    /**
     * Get all existing models from app/Models directory
     */
    private function getAllModels(): array
    {
        $dir = $this->basePath('app/Models');
        if (!is_dir($dir)) {
            return [];
        }

        $namespace = $this->getAppNamespace();
        $models = [];

        $files = glob("{$dir}/*.php");
        foreach ($files as $file) {
            $name = basename($file, '.php');

            // Skip base Model class if it exists
            if ($name === 'Model') {
                continue;
            }

            $models[] = [
                'name' => $name,
                'path' => $file,
                'class' => "{$namespace}\\Models\\{$name}",
            ];
        }

        // Sort alphabetically
        usort($models, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $models;
    }

    /**
     * Run the model:wizard command for the given model name
     */
    private function runModelWizard(string $name): int
    {
        $this->line('');
        $this->section('Model Wizard');
        $this->line('');

        // We'll replicate the wizard functionality inline
        // since we can't easily call another command with interactive input

        $this->info("Creating model: {$name}");
        $this->line('');

        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            if (!$this->confirm("Model '{$name}' already exists. Overwrite?", false)) {
                $this->line('Using existing model.');
                return self::SUCCESS;
            }
        }

        // Create Blueprint
        $blueprint = \ZephyrPHP\Database\Builder\Blueprint::create($name);

        // Table Name
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        $defaultTable = str_ends_with($tableName, 's') ? $tableName : $tableName . 's';
        $table = $this->ask("Table name", $defaultTable);
        $blueprint->table($table);

        // Columns
        $this->section('Add Columns');
        $this->line('(Press Enter with empty name to finish adding columns)');
        $this->line('');

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
        ];

        $columnCount = 0;
        $validations = [];

        while (true) {
            $columnCount++;
            $colName = $this->ask("Column #{$columnCount} name", '');
            if (empty($colName)) {
                break;
            }

            // Column type
            $colType = $this->choice(
                "Type for '{$colName}'",
                array_values($columnTypes),
                'String (VARCHAR)'
            );
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
            ];

            $selectedModifiers = $this->multiChoice(
                'Select column options (space to select, enter to confirm)',
                $modifierOptions,
                []
            );

            $isNullable = in_array('Nullable - Allow NULL values', $selectedModifiers);
            if ($isNullable) {
                $blueprint->nullable();
            }
            if (in_array('Unique - Must be unique in table', $selectedModifiers)) {
                $blueprint->unique();
            }
            if (in_array('Indexed - Add database index', $selectedModifiers)) {
                $blueprint->index();
            }

            // Validation rules selection based on column type
            if (in_array($colType, ['string', 'text'])) {
                $validations[$colName] = $this->selectStringValidation($colName, $colType, $isNullable);
            } elseif (in_array($colType, ['integer', 'bigint', 'float', 'decimal'])) {
                $validations[$colName] = $this->selectNumericValidation($colName, $isNullable);
            } elseif (in_array($colType, ['date', 'datetime'])) {
                $validations[$colName] = $this->selectDateValidation($colName, $isNullable);
            } elseif (!$isNullable) {
                $validations[$colName] = ['required'];
            }

            // Remove empty validation arrays
            if (empty($validations[$colName])) {
                unset($validations[$colName]);
            }

            $this->success("Added: {$colName} ({$colType})");
            $this->line('');
        }

        // Model Options
        $this->section('Model Options');

        $timestamps = $this->confirm('Add timestamps (createdAt, updatedAt)?', true);
        $blueprint->timestamps($timestamps);

        $softDeletes = $this->confirm('Add soft deletes (deletedAt)?', false);
        if ($softDeletes) {
            $blueprint->softDeletes(true);
        }

        // Set validations
        if (!empty($validations)) {
            $blueprint->setValidations($validations);
        }

        // Generate and save
        $this->line('');
        try {
            $content = $blueprint->build();
            if (file_put_contents($path, $content) !== false) {
                $this->success("Model created: app/Models/{$name}.php");
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
     * Find existing model by checking multiple naming conventions
     */
    private function findExistingModel(string $name): ?array
    {
        $dir = $this->basePath('app/Models');
        if (!is_dir($dir)) {
            return null;
        }

        $namespace = $this->getAppNamespace();

        // Check various naming patterns
        $patterns = [
            $name,                              // User
            $this->toPlural($name),             // Users
            ucfirst($name),                     // User (ensure PascalCase)
            ucfirst($this->toPlural($name)),    // Users (ensure PascalCase)
            $this->toSingular($name),           // User (if input was Users)
        ];

        // Remove duplicates
        $patterns = array_unique($patterns);

        foreach ($patterns as $pattern) {
            $path = "{$dir}/{$pattern}.php";
            if (file_exists($path)) {
                return [
                    'name' => $pattern,
                    'path' => $path,
                    'class' => "{$namespace}\\Models\\{$pattern}",
                ];
            }
        }

        return null;
    }

    /**
     * Parse existing model to extract fields and validation rules
     */
    private function parseModelFields(string $path): void
    {
        $content = file_get_contents($path);

        // Parse ORM Column attributes to get field names and types
        // Pattern: #[ORM\Column(type: 'string', ...)]
        // followed by: private ?string $fieldName
        preg_match_all(
            '/#\[ORM\\\\Column\(([^)]+)\)\]\s*(?:private|protected|public)\s+(\??\w+)\s+\$(\w+)/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $attributes = $match[1];
            $phpType = $match[2];
            $fieldName = $match[3];

            // Skip id, createdAt, updatedAt, deletedAt
            if (in_array($fieldName, ['id', 'createdAt', 'updatedAt', 'deletedAt'])) {
                continue;
            }

            // Parse type from attributes
            $type = 'string';
            if (preg_match("/type:\s*['\"](\w+)['\"]/", $attributes, $typeMatch)) {
                $type = $typeMatch[1];
            }

            // Check if nullable
            $nullable = str_contains($attributes, 'nullable: true') || str_starts_with($phpType, '?');

            $this->modelFields[$fieldName] = [
                'type' => $type,
                'nullable' => $nullable,
            ];
        }

        // Check if model has validation rules
        $this->modelHasValidation = str_contains($content, 'static array $rules');

        if (empty($this->modelFields)) {
            // Fallback: assume basic name field
            $this->modelFields = ['name' => ['type' => 'string', 'nullable' => false]];
        }
    }

    /**
     * Convert word to singular form
     */
    private function toSingular(string $word): string
    {
        // Common plurals
        if (preg_match('/ies$/i', $word)) {
            return preg_replace('/ies$/i', 'y', $word);
        }
        if (preg_match('/ses$/i', $word)) {
            return preg_replace('/ses$/i', 's', $word);
        }
        if (preg_match('/([^s])s$/i', $word)) {
            return preg_replace('/s$/i', '', $word);
        }
        return $word;
    }

    /**
     * Convert word to plural form
     */
    private function toPlural(string $word): string
    {
        // Already plural
        if (preg_match('/s$/i', $word) && !preg_match('/ss$/i', $word)) {
            return $word;
        }
        // Words ending in y (preceded by consonant)
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return preg_replace('/y$/i', 'ies', $word);
        }
        // Words ending in s, x, z, ch, sh
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        return $word . 's';
    }

    private function createModel(string $name, string $namespace): void
    {
        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->warning("Model already exists: {$name}");
            return;
        }

        // Convert PascalCase to snake_case for table name
        $tableName = $this->toPlural(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)));

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Models;

use Doctrine\\ORM\\Mapping as ORM;

#[ORM\\Entity]
#[ORM\\Table(name: '{$tableName}')]
#[ORM\\HasLifecycleCallbacks]
class {$name}
{
    /**
     * Validation rules for this model
     * Used by controllers for form validation
     */
    public static array \$rules = [
        'name' => 'required|min:2|max:255',
    ];

    /**
     * Custom validation messages
     */
    public static array \$messages = [
        'name.required' => 'Please enter a name.',
        'name.min' => 'Name must be at least 2 characters.',
        'name.max' => 'Name cannot exceed 255 characters.',
    ];

    #[ORM\\Id]
    #[ORM\\GeneratedValue]
    #[ORM\\Column(type: 'integer')]
    private ?int \$id = null;

    #[ORM\\Column(type: 'string', length: 255)]
    private string \$name = '';

    #[ORM\\Column(type: 'datetime', nullable: true)]
    private ?\\DateTimeInterface \$createdAt = null;

    #[ORM\\Column(type: 'datetime', nullable: true)]
    private ?\\DateTimeInterface \$updatedAt = null;

    #[ORM\\PrePersist]
    public function onPrePersist(): void
    {
        \$this->createdAt = new \\DateTime();
        \$this->updatedAt = new \\DateTime();
    }

    #[ORM\\PreUpdate]
    public function onPreUpdate(): void
    {
        \$this->updatedAt = new \\DateTime();
    }

    public function getId(): ?int
    {
        return \$this->id;
    }

    public function getName(): string
    {
        return \$this->name;
    }

    public function setName(string \$name): self
    {
        \$this->name = \$name;
        return \$this;
    }

    public function getCreatedAt(): ?\\DateTimeInterface
    {
        return \$this->createdAt;
    }

    public function getUpdatedAt(): ?\\DateTimeInterface
    {
        return \$this->updatedAt;
    }
}
PHP;

        file_put_contents($path, $content);
        $this->success("Created: app/Models/{$name}.php");
    }

    private function createController(string $name, string $namespace, string $nameLower, string $namePlural): void
    {
        $dir = $this->basePath('app/Controllers');
        $this->ensureDirectory($dir);

        $controllerName = "{$name}Controller";
        $path = "{$dir}/{$controllerName}.php";

        if (file_exists($path)) {
            $this->warning("Controller already exists: {$controllerName}");
            return;
        }

        // Generate field setters for store/update methods
        $fieldSetters = $this->generateFieldSetters($nameLower);
        $validationRules = $this->generateValidationRulesCode($name);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use {$namespace}\\Models\\{$name};
use Doctrine\\ORM\\EntityManagerInterface;
use ZephyrPHP\\Core\\Http\\Request;
use ZephyrPHP\\Core\\Http\\Response;
use ZephyrPHP\\Validation\\Validator;

class {$controllerName}
{
    private EntityManagerInterface \$em;

    public function __construct(EntityManagerInterface \$entityManager)
    {
        \$this->em = \$entityManager;
    }

    /**
     * Display a listing of {$namePlural}
     */
    public function index(Request \$request): Response
    {
        \$repository = \$this->em->getRepository({$name}::class);
        \${$namePlural} = \$repository->findAll();

        return view('{$namePlural}/index', ['{$namePlural}' => \${$namePlural}]);
    }

    /**
     * Show the form for creating a new {$nameLower}
     */
    public function create(Request \$request): Response
    {
        return view('{$namePlural}/create', [
            'errors' => [],
            'old' => [],
        ]);
    }

    /**
     * Store a newly created {$nameLower}
     */
    public function store(Request \$request): Response
    {
        \$data = \$request->all();

        // Validate input
{$validationRules}

        if (\$validator->fails()) {
            return view('{$namePlural}/create', [
                'errors' => \$validator->errors(),
                'old' => \$data,
            ]);
        }

        // Create new {$nameLower}
        \${$nameLower} = new {$name}();
{$fieldSetters}

        \$this->em->persist(\${$nameLower});
        \$this->em->flush();

        return redirect('/{$namePlural}')->with('success', '{$name} created successfully!');
    }

    /**
     * Display the specified {$nameLower}
     */
    public function show(Request \$request, int \$id): Response
    {
        \${$nameLower} = \$this->em->find({$name}::class, \$id);

        if (!\${$nameLower}) {
            return redirect('/{$namePlural}')->with('error', '{$name} not found.');
        }

        return view('{$namePlural}/show', ['{$nameLower}' => \${$nameLower}]);
    }

    /**
     * Show the form for editing the specified {$nameLower}
     */
    public function edit(Request \$request, int \$id): Response
    {
        \${$nameLower} = \$this->em->find({$name}::class, \$id);

        if (!\${$nameLower}) {
            return redirect('/{$namePlural}')->with('error', '{$name} not found.');
        }

        return view('{$namePlural}/edit', [
            '{$nameLower}' => \${$nameLower},
            'errors' => [],
            'old' => [],
        ]);
    }

    /**
     * Update the specified {$nameLower}
     */
    public function update(Request \$request, int \$id): Response
    {
        \${$nameLower} = \$this->em->find({$name}::class, \$id);

        if (!\${$nameLower}) {
            return redirect('/{$namePlural}')->with('error', '{$name} not found.');
        }

        \$data = \$request->all();

        // Validate input
{$validationRules}

        if (\$validator->fails()) {
            return view('{$namePlural}/edit', [
                '{$nameLower}' => \${$nameLower},
                'errors' => \$validator->errors(),
                'old' => \$data,
            ]);
        }

        // Update {$nameLower}
{$fieldSetters}

        \$this->em->flush();

        return redirect('/{$namePlural}')->with('success', '{$name} updated successfully!');
    }

    /**
     * Remove the specified {$nameLower}
     */
    public function destroy(Request \$request, int \$id): Response
    {
        \${$nameLower} = \$this->em->find({$name}::class, \$id);

        if (!\${$nameLower}) {
            return redirect('/{$namePlural}')->with('error', '{$name} not found.');
        }

        \$this->em->remove(\${$nameLower});
        \$this->em->flush();

        return redirect('/{$namePlural}')->with('success', '{$name} deleted successfully!');
    }
}
PHP;

        file_put_contents($path, $content);
        $this->success("Created: app/Controllers/{$controllerName}.php");
    }

    /**
     * Generate field setters code for controller
     */
    private function generateFieldSetters(string $nameLower): string
    {
        $lines = [];
        foreach ($this->modelFields as $field => $config) {
            $setter = 'set' . ucfirst($field);
            $type = $config['type'] ?? 'string';

            // Cast form values to proper PHP types
            $value = match($type) {
                'integer', 'smallint' => "(int)(\$data['{$field}'] ?? 0)",
                'bigint' => "(int)(\$data['{$field}'] ?? 0)",
                'float', 'decimal' => "(float)(\$data['{$field}'] ?? 0)",
                'boolean' => "(bool)(\$data['{$field}'] ?? false)",
                default => "\$data['{$field}'] ?? ''",
            };

            $lines[] = "        \${$nameLower}->{$setter}({$value});";
        }
        return implode("\n", $lines);
    }

    /**
     * Generate validation rules code
     */
    private function generateValidationRulesCode(string $name): string
    {
        if ($this->modelHasValidation) {
            return "        \$validator = Validator::make(\$data, {$name}::\$rules, {$name}::\$messages ?? []);";
        }

        // Generate inline rules from model fields
        $rules = [];
        foreach ($this->modelFields as $field => $config) {
            $fieldRules = [];
            if (!$config['nullable']) {
                $fieldRules[] = 'required';
            }
            if ($config['type'] === 'string') {
                $fieldRules[] = 'max:255';
            }
            if ($config['type'] === 'integer' || $config['type'] === 'bigint') {
                $fieldRules[] = 'numeric';
            }
            if ($config['type'] === 'boolean') {
                $fieldRules[] = 'boolean';
            }
            $rules[$field] = implode('|', $fieldRules);
        }

        $rulesStr = var_export($rules, true);
        $rulesStr = preg_replace('/^/m', '            ', $rulesStr);
        $rulesStr = ltrim($rulesStr);

        return "        \$validator = Validator::make(\$data, {$rulesStr});";
    }

    private function createViews(string $name, string $nameLower, string $namePlural): void
    {
        // Get view path from .env or use default
        $viewsPath = $this->getViewsPath();
        $dir = $this->basePath("{$viewsPath}/{$namePlural}");
        $this->ensureDirectory($dir);

        // Generate form fields HTML
        $formFields = $this->generateFormFields();
        $tableHeaders = $this->generateTableHeaders();
        $tableCells = $this->generateTableCells($nameLower);
        $showFields = $this->generateShowFields($nameLower);

        // Index view
        $indexContent = <<<HTML
{% extends 'layouts/app.twig' %}

{% block content %}
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>{$name} List</h1>
        <a href="/{$namePlural}/create" class="btn btn-primary">Create New {$name}</a>
    </div>

    {% if session.flash.success %}
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session.flash.success }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}

    {% if session.flash.error %}
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session.flash.error }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    {% endif %}

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
{$tableHeaders}
                    <th>Created</th>
                    <th width="200">Actions</th>
                </tr>
            </thead>
            <tbody>
                {% for {$nameLower} in {$namePlural} %}
                <tr>
                    <td>{{ {$nameLower}.id }}</td>
{$tableCells}
                    <td>{{ {$nameLower}.createdAt|date('Y-m-d H:i') }}</td>
                    <td>
                        <a href="/{$namePlural}/{{ {$nameLower}.id }}" class="btn btn-sm btn-info">View</a>
                        <a href="/{$namePlural}/{{ {$nameLower}.id }}/edit" class="btn btn-sm btn-warning">Edit</a>
                        <form action="/{$namePlural}/{{ {$nameLower}.id }}" method="POST" style="display: inline;">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this {$nameLower}?')">Delete</button>
                        </form>
                    </td>
                </tr>
                {% else %}
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">No {$namePlural} found. <a href="/{$namePlural}/create">Create one</a></td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
HTML;

        // Create view
        $createContent = <<<HTML
{% extends 'layouts/app.twig' %}

{% block content %}
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Create {$name}</h4>
                </div>
                <div class="card-body">
                    {# Flash Messages #}
                    {% if session.flash.success %}
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session.flash.success }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    {% endif %}

                    {% if session.flash.error %}
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session.flash.error }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    {% endif %}

                    {# Validation Errors Summary #}
                    {% if session.flash.errors.any() or errors is defined and errors is not empty %}
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            {% for field, messages in session.flash.errors %}
                                {% for message in messages %}
                                    <li>{{ message }}</li>
                                {% endfor %}
                            {% endfor %}
                            {% if errors is defined %}
                                {% for field, messages in errors %}
                                    {% for message in messages %}
                                        <li>{{ message }}</li>
                                    {% endfor %}
                                {% endfor %}
                            {% endif %}
                        </ul>
                    </div>
                    {% endif %}

                    <form action="/{$namePlural}" method="POST">
{$formFields}

                        <div class="mb-3">
                            <small class="text-muted"><span class="text-danger">*</span> Required fields</small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Create {$name}</button>
                            <a href="/{$namePlural}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
HTML;

        // Edit view
        $editFormFields = $this->generateFormFields($nameLower, true);
        $editContent = <<<HTML
{% extends 'layouts/app.twig' %}

{% block content %}
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Edit {$name}</h4>
                </div>
                <div class="card-body">
                    {# Flash Messages #}
                    {% if session.flash.success %}
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session.flash.success }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    {% endif %}

                    {% if session.flash.error %}
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session.flash.error }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    {% endif %}

                    {# Validation Errors Summary #}
                    {% if session.flash.errors.any() or errors is defined and errors is not empty %}
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            {% for field, messages in session.flash.errors %}
                                {% for message in messages %}
                                    <li>{{ message }}</li>
                                {% endfor %}
                            {% endfor %}
                            {% if errors is defined %}
                                {% for field, messages in errors %}
                                    {% for message in messages %}
                                        <li>{{ message }}</li>
                                    {% endfor %}
                                {% endfor %}
                            {% endif %}
                        </ul>
                    </div>
                    {% endif %}

                    <form action="/{$namePlural}/{{ {$nameLower}.id }}" method="POST">
                        <input type="hidden" name="_method" value="PUT">

{$editFormFields}

                        <div class="mb-3">
                            <small class="text-muted"><span class="text-danger">*</span> Required fields</small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Update {$name}</button>
                            <a href="/{$namePlural}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
HTML;

        // Show view
        $showContent = <<<HTML
{% extends 'layouts/app.twig' %}

{% block content %}
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">{$name} Details</h4>
                    <div>
                        <a href="/{$namePlural}/{{ {$nameLower}.id }}/edit" class="btn btn-warning btn-sm">Edit</a>
                        <form action="/{$namePlural}/{{ {$nameLower}.id }}" method="POST" style="display: inline;">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="30%">ID</th>
                            <td>{{ {$nameLower}.id }}</td>
                        </tr>
{$showFields}
                        <tr>
                            <th>Created At</th>
                            <td>{{ {$nameLower}.createdAt|date('Y-m-d H:i:s') }}</td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td>{{ {$nameLower}.updatedAt|date('Y-m-d H:i:s') }}</td>
                        </tr>
                    </table>

                    <a href="/{$namePlural}" class="btn btn-secondary">Back to List</a>
                </div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
HTML;

        file_put_contents("{$dir}/index.twig", $indexContent);
        $this->success("Created: {$viewsPath}/{$namePlural}/index.twig");

        file_put_contents("{$dir}/create.twig", $createContent);
        $this->success("Created: {$viewsPath}/{$namePlural}/create.twig");

        file_put_contents("{$dir}/edit.twig", $editContent);
        $this->success("Created: {$viewsPath}/{$namePlural}/edit.twig");

        file_put_contents("{$dir}/show.twig", $showContent);
        $this->success("Created: {$viewsPath}/{$namePlural}/show.twig");
    }

    /**
     * Generate form fields HTML based on model fields
     */
    private function generateFormFields(string $varName = '', bool $isEdit = false): string
    {
        $lines = [];
        foreach ($this->modelFields as $field => $config) {
            $label = ucfirst(str_replace('_', ' ', $field));
            $isRequired = !$config['nullable'];
            $required = $isRequired ? 'required' : '';
            $requiredStar = $isRequired ? ' <span class="text-danger">*</span>' : '';
            $inputType = $this->getInputType($config['type']);

            // Use flash.old for old input values
            if ($isEdit && $varName) {
                $value = "{{ session.flash.old.{$field}|default(old.{$field}|default({$varName}.{$field})) }}";
            } else {
                $value = "{{ session.flash.old.{$field}|default(old.{$field}|default('')) }}";
            }

            // Check for errors - use session.flash.errors.FIELD which returns array
            $hasError = "session.flash.errors.{$field}|default(errors.{$field}|default([]))|length > 0";
            $errorMessage = "(session.flash.errors.{$field}|default(errors.{$field}|default([])))|first";

            $lines[] = "                        <div class=\"mb-3\">";
            $lines[] = "                            <label for=\"{$field}\" class=\"form-label\">{$label}{$requiredStar}</label>";

            if ($config['type'] === 'text') {
                $lines[] = "                            <textarea class=\"form-control {% if {$hasError} %}is-invalid{% endif %}\" id=\"{$field}\" name=\"{$field}\" rows=\"4\" {$required}>{$value}</textarea>";
            } elseif ($config['type'] === 'boolean') {
                $checkedValue = $isEdit ? "{$varName}.{$field}" : "false";
                $lines[] = "                            <div class=\"form-check\">";
                $lines[] = "                                <input type=\"checkbox\" class=\"form-check-input {% if {$hasError} %}is-invalid{% endif %}\" id=\"{$field}\" name=\"{$field}\" value=\"1\" {% if session.flash.old.{$field}|default(old.{$field}|default({$checkedValue})) %}checked{% endif %}>";
                $lines[] = "                                <label class=\"form-check-label\" for=\"{$field}\">Yes</label>";
                $lines[] = "                            </div>";
            } else {
                $lines[] = "                            <input type=\"{$inputType}\" class=\"form-control {% if {$hasError} %}is-invalid{% endif %}\" id=\"{$field}\" name=\"{$field}\" value=\"{$value}\" {$required}>";
            }

            $lines[] = "                            {% if {$hasError} %}";
            $lines[] = "                                <div class=\"invalid-feedback\">{{ {$errorMessage} }}</div>";
            $lines[] = "                            {% endif %}";
            $lines[] = "                        </div>";
        }

        return implode("\n", $lines);
    }

    /**
     * Get HTML input type from database type
     */
    private function getInputType(string $dbType): string
    {
        return match ($dbType) {
            'integer', 'bigint', 'smallint' => 'number',
            'float', 'decimal' => 'number',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'time' => 'time',
            'boolean' => 'checkbox',
            default => 'text',
        };
    }

    /**
     * Generate table headers for index view
     */
    private function generateTableHeaders(): string
    {
        $lines = [];
        foreach ($this->modelFields as $field => $config) {
            $label = ucfirst(str_replace('_', ' ', $field));
            $lines[] = "                    <th>{$label}</th>";
        }
        return implode("\n", $lines);
    }

    /**
     * Generate table cells for index view
     */
    private function generateTableCells(string $varName): string
    {
        $lines = [];
        foreach ($this->modelFields as $field => $config) {
            if ($config['type'] === 'boolean') {
                $lines[] = "                    <td>{{ {$varName}.{$field} ? 'Yes' : 'No' }}</td>";
            } elseif (in_array($config['type'], ['datetime', 'date'])) {
                $lines[] = "                    <td>{{ {$varName}.{$field}|date('Y-m-d') }}</td>";
            } else {
                $lines[] = "                    <td>{{ {$varName}.{$field} }}</td>";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Generate show view fields
     */
    private function generateShowFields(string $varName): string
    {
        $lines = [];
        foreach ($this->modelFields as $field => $config) {
            $label = ucfirst(str_replace('_', ' ', $field));
            $lines[] = "                        <tr>";
            $lines[] = "                            <th>{$label}</th>";

            if ($config['type'] === 'boolean') {
                $lines[] = "                            <td>{{ {$varName}.{$field} ? 'Yes' : 'No' }}</td>";
            } elseif (in_array($config['type'], ['datetime', 'date'])) {
                $lines[] = "                            <td>{{ {$varName}.{$field}|date('Y-m-d H:i:s') }}</td>";
            } else {
                $lines[] = "                            <td>{{ {$varName}.{$field} }}</td>";
            }

            $lines[] = "                        </tr>";
        }
        return implode("\n", $lines);
    }

    /**
     * Get the views path from .env or use default
     */
    private function getViewsPath(): string
    {
        // Try to read from .env file
        $envFile = $this->basePath('.env');
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^VIEWS_PATH\s*=\s*["\']?([^"\'\r\n]+)["\']?/m', $content, $matches)) {
                return ltrim($matches[1], '/');
            }
        }
        // Default path
        return 'pages';
    }

    private function addRoutes(string $name, string $namePlural, string $namespace): bool
    {
        $routesFile = $this->basePath('routes/web.php');

        if (!file_exists($routesFile)) {
            $this->warning("Routes file not found: routes/web.php");
            $this->showRoutes($name, $namePlural, $namespace);
            return false;
        }

        $content = file_get_contents($routesFile);
        $controllerClass = "{$namespace}\\Controllers\\{$name}Controller";

        // Check if routes already exist
        if (str_contains($content, "/{$namePlural}")) {
            $this->warning("Routes for '/{$namePlural}' already exist in routes/web.php");
            return true;
        }

        // Detect routing style: Route:: (static) or $router-> (instance)
        $useStaticStyle = str_contains($content, 'Route::');

        // Generate routes code based on detected style
        if ($useStaticStyle) {
            $routesCode = <<<PHP


// {$name} CRUD routes
Route::get('/{$namePlural}', [{$controllerClass}::class, 'index']);
Route::get('/{$namePlural}/create', [{$controllerClass}::class, 'create']);
Route::post('/{$namePlural}', [{$controllerClass}::class, 'store']);
Route::get('/{$namePlural}/{id}', [{$controllerClass}::class, 'show']);
Route::get('/{$namePlural}/{id}/edit', [{$controllerClass}::class, 'edit']);
Route::put('/{$namePlural}/{id}', [{$controllerClass}::class, 'update']);
Route::delete('/{$namePlural}/{id}', [{$controllerClass}::class, 'destroy']);
PHP;
        } else {
            $routesCode = <<<PHP


// {$name} CRUD routes
\$router->get('/{$namePlural}', [{$controllerClass}::class, 'index']);
\$router->get('/{$namePlural}/create', [{$controllerClass}::class, 'create']);
\$router->post('/{$namePlural}', [{$controllerClass}::class, 'store']);
\$router->get('/{$namePlural}/{id}', [{$controllerClass}::class, 'show']);
\$router->get('/{$namePlural}/{id}/edit', [{$controllerClass}::class, 'edit']);
\$router->put('/{$namePlural}/{id}', [{$controllerClass}::class, 'update']);
\$router->delete('/{$namePlural}/{id}', [{$controllerClass}::class, 'destroy']);
PHP;
        }

        // Append routes to file
        file_put_contents($routesFile, $content . $routesCode);
        $this->success("Added routes to: routes/web.php");

        return true;
    }

    private function showRoutes(string $name, string $namePlural, string $namespace): void
    {
        $controllerClass = "{$namespace}\\Controllers\\{$name}Controller";

        $this->line('');
        $this->line('Add these routes to routes/web.php:');
        $this->line('');
        $this->line("// {$name} CRUD routes");
        $this->line("Route::get('/{$namePlural}', [{$controllerClass}::class, 'index']);");
        $this->line("Route::get('/{$namePlural}/create', [{$controllerClass}::class, 'create']);");
        $this->line("Route::post('/{$namePlural}', [{$controllerClass}::class, 'store']);");
        $this->line("Route::get('/{$namePlural}/{id}', [{$controllerClass}::class, 'show']);");
        $this->line("Route::get('/{$namePlural}/{id}/edit', [{$controllerClass}::class, 'edit']);");
        $this->line("Route::put('/{$namePlural}/{id}', [{$controllerClass}::class, 'update']);");
        $this->line("Route::delete('/{$namePlural}/{id}', [{$controllerClass}::class, 'destroy']);");
    }

    /**
     * Select string validation rules interactively
     */
    private function selectStringValidation(string $colName, string $colType, bool $isNullable): array
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

        if (!$this->confirm("Add validation rules for '{$colName}'?", false)) {
            return $isNullable ? [] : ['required'];
        }

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

        // If not nullable and no required rule was selected, add it
        if (!$isNullable && !in_array('required', $rules)) {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    /**
     * Select numeric validation rules interactively
     */
    private function selectNumericValidation(string $colName, bool $isNullable): array
    {
        $validationOptions = [
            'Required - Field cannot be empty',
            'Positive - Must be > 0',
            'Negative - Must be < 0',
            'Range - Min/Max value',
        ];

        if (!$this->confirm("Add validation rules for '{$colName}'?", false)) {
            return $isNullable ? [] : ['required'];
        }

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
                if ($minVal !== '') {
                    $rules[] = "min:{$minVal}";
                }
                if ($maxVal !== '') {
                    $rules[] = "max:{$maxVal}";
                }
            }
        }

        // If not nullable and no required rule was selected, add it
        if (!$isNullable && !in_array('required', $rules)) {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    /**
     * Select date validation rules interactively
     */
    private function selectDateValidation(string $colName, bool $isNullable): array
    {
        $validationOptions = [
            'Required - Field cannot be empty',
            'Past Date - Must be in the past',
            'Future Date - Must be in the future',
            'After Today - Must be today or later',
        ];

        if (!$this->confirm("Add validation rules for '{$colName}'?", false)) {
            return $isNullable ? [] : ['required'];
        }

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

        // If not nullable and no required rule was selected, add it
        if (!$isNullable && !in_array('required', $rules)) {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    /**
     * Check if database module is enabled
     */
    private function isDatabaseModuleEnabled(): bool
    {
        $modulesPath = $this->basePath('config/modules.php');

        if (!file_exists($modulesPath)) {
            return false;
        }

        $modules = require $modulesPath;

        return isset($modules['database']) && $modules['database'] === true;
    }

    /**
     * Enable the database module in config/modules.php
     */
    private function enableDatabaseModule(): void
    {
        $modulesPath = $this->basePath('config/modules.php');

        if (!file_exists($modulesPath)) {
            $this->warning('config/modules.php not found');
            return;
        }

        $content = file_get_contents($modulesPath);

        // Replace 'database' => false with 'database' => true
        $content = preg_replace(
            "/(['\"]database['\"])\s*=>\s*false/",
            "$1 => true",
            $content
        );

        file_put_contents($modulesPath, $content);
    }
}
