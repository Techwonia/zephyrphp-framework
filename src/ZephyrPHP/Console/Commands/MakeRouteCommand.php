<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:route',
    description: 'Create a new web route with optional controller and view template'
)]
class MakeRouteCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('uri', InputArgument::REQUIRED, 'The route URI (e.g., /about, /blog/blog-detail)')
            ->addOption('controller', 'c', InputOption::VALUE_OPTIONAL, 'Controller class to use (default: HomeController)', 'HomeController')
            ->addOption('layout', 'l', InputOption::VALUE_OPTIONAL, 'Twig layout to extend (e.g., app, base, none)')
            ->addOption('no-controller', null, InputOption::VALUE_NONE, 'Generate a closure-based route instead of controller')
            ->addOption('method', 'm', InputOption::VALUE_OPTIONAL, 'HTTP method (GET, POST, PUT, PATCH, DELETE)', 'GET')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Named route alias (e.g., blog.detail)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        // Parse and normalize the URI
        $uri = $this->normalizeUri($input->getArgument('uri'));
        $httpMethod = strtolower($input->getOption('method'));
        $noController = $input->getOption('no-controller');
        $controllerName = $input->getOption('controller');
        $layoutOption = $input->getOption('layout');
        $routeName = $input->getOption('name');

        // Validate HTTP method
        $validMethods = ['get', 'post', 'put', 'patch', 'delete'];
        if (!in_array($httpMethod, $validMethods)) {
            $this->error("Invalid HTTP method: {$httpMethod}. Valid methods: " . implode(', ', $validMethods));
            return self::FAILURE;
        }

        $this->title("Creating Route: {$uri}");

        // Check for duplicate route
        if ($this->routeExists($uri)) {
            $this->error("Route '{$uri}' already exists in routes/web.php");
            return self::FAILURE;
        }

        // Resolve views path
        $viewsPath = $this->getViewsPath();

        // Resolve layout
        $layout = $this->resolveLayout($layoutOption, $viewsPath);

        // Derive template path and method name from URI
        $templatePath = $this->uriToTemplatePath($uri);
        $methodName = $this->uriToMethodName($uri);
        $routeName = $routeName ?? $this->uriToRouteName($uri);

        $namespace = $this->getAppNamespace();

        $created = [];

        // --- Step 1: Create the Twig view template ---
        $this->section('Creating View Template');
        $viewResult = $this->createViewTemplate($viewsPath, $templatePath, $layout);
        if ($viewResult) {
            $created[] = "View: {$viewsPath}/{$templatePath}.twig";
        }

        // --- Step 2: Handle controller or closure ---
        if ($noController) {
            // Closure-based route
            $this->section('Adding Closure Route');
            $routeAdded = $this->addClosureRoute($uri, $httpMethod, $templatePath, $routeName);
        } else {
            // Controller-based route
            $this->section('Setting Up Controller');

            // Ensure controller name ends with "Controller"
            $controllerName = $this->sanitizeName($controllerName);
            if (!str_ends_with($controllerName, 'Controller')) {
                $controllerName .= 'Controller';
            }

            $controllerResult = $this->ensureControllerMethod($namespace, $controllerName, $methodName, $templatePath);
            if ($controllerResult === false) {
                return self::FAILURE;
            }
            if ($controllerResult === 'created_controller') {
                $created[] = "Controller: app/Controllers/{$controllerName}.php";
            }
            $created[] = "Method: {$controllerName}::{$methodName}()";

            $this->section('Adding Controller Route');
            $routeAdded = $this->addControllerRoute($uri, $httpMethod, $namespace, $controllerName, $methodName, $routeName);
        }

        if ($routeAdded) {
            $created[] = "Route: {$uri}";
        }

        // --- Summary ---
        $this->section('Summary');
        $this->line('');
        foreach ($created as $item) {
            $this->line("  <info>✓</info> {$item}");
        }
        $this->line('');
        $this->success("Route '{$uri}' created successfully!");

        return self::SUCCESS;
    }

    // =========================================================================
    // URI Helpers
    // =========================================================================

    /**
     * Normalize the URI (ensure leading slash, remove trailing slash, trim)
     */
    private function normalizeUri(string $uri): string
    {
        $uri = trim($uri, ' /');
        return '/' . $uri;
    }

    /**
     * Convert URI to template path (e.g., /blog/blog-detail → blog/blog-detail)
     */
    private function uriToTemplatePath(string $uri): string
    {
        return ltrim($uri, '/');
    }

    /**
     * Convert URI to a camelCase method name from the last segment
     * e.g., /blog/blog-detail → blogDetail
     * e.g., /about → about
     * e.g., / → index
     */
    private function uriToMethodName(string $uri): string
    {
        $path = ltrim($uri, '/');

        if ($path === '') {
            return 'index';
        }

        // Take the last segment
        $segments = explode('/', $path);
        $lastSegment = end($segments);

        // Convert kebab-case to camelCase
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $lastSegment))));
    }

    /**
     * Convert URI to a dot-notation route name
     * e.g., /blog/blog-detail → blog.blog-detail
     * e.g., /about → about
     */
    private function uriToRouteName(string $uri): string
    {
        $path = ltrim($uri, '/');

        if ($path === '') {
            return 'home';
        }

        return str_replace('/', '.', $path);
    }

    // =========================================================================
    // Views Path
    // =========================================================================

    /**
     * Get the views path from .env or use default
     */
    private function getViewsPath(): string
    {
        $envFile = $this->basePath('.env');
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^VIEWS_PATH\s*=\s*["\']?([^"\'\r\n]+)["\']?/m', $content, $matches)) {
                return ltrim($matches[1], '/');
            }
        }
        return 'pages';
    }

    // =========================================================================
    // Layout Discovery & Selection
    // =========================================================================

    /**
     * Discover available layouts in the views/layouts directory
     */
    private function discoverLayouts(string $viewsPath): array
    {
        $layoutsDir = $this->basePath("{$viewsPath}/layouts");
        $layouts = [];

        if (!is_dir($layoutsDir)) {
            return $layouts;
        }

        $files = glob($layoutsDir . '/*.twig');
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $layouts[] = $name;
        }

        sort($layouts);
        return $layouts;
    }

    /**
     * Resolve the layout — either from --layout flag or interactive prompt
     */
    private function resolveLayout(?string $layoutOption, string $viewsPath): ?string
    {
        // If explicitly set via flag
        if ($layoutOption !== null) {
            if ($layoutOption === 'none') {
                return null;
            }
            return $layoutOption;
        }

        // Interactive: discover and prompt
        $availableLayouts = $this->discoverLayouts($viewsPath);

        if (empty($availableLayouts)) {
            $this->info('No layouts found in ' . $viewsPath . '/layouts/');
            $this->line('Creating a standalone page (no layout).');
            return null;
        }

        $choices = $availableLayouts;
        $choices[] = 'none (standalone page)';

        $selected = $this->choice(
            'Which layout should this page use?',
            $choices,
            $choices[0]
        );

        if (str_starts_with($selected, 'none')) {
            return null;
        }

        return $selected;
    }

    // =========================================================================
    // View Template Generation
    // =========================================================================

    /**
     * Create the Twig view template file
     */
    private function createViewTemplate(string $viewsPath, string $templatePath, ?string $layout): bool
    {
        $fullPath = $this->basePath("{$viewsPath}/{$templatePath}.twig");

        if (file_exists($fullPath)) {
            $this->warning("View template already exists: {$viewsPath}/{$templatePath}.twig");

            if (!$this->confirm('Overwrite existing template?', false)) {
                $this->info('Skipped view template creation.');
                return false;
            }
        }

        // Ensure the directory exists for nested paths
        $dir = dirname($fullPath);
        $this->ensureDirectory($dir);

        // Generate page title from template path
        $pageTitle = $this->generatePageTitle($templatePath);

        if ($layout !== null) {
            // Layout-based template
            $content = <<<TWIG
{% extends 'layouts/{$layout}.twig' %}

{% block title %}{$pageTitle}{% endblock %}

{% block content %}
<div class="container">
    <h1>{$pageTitle}</h1>
    <p>This is the {$pageTitle} page.</p>
</div>
{% endblock %}
TWIG;
        } else {
            // Standalone page (no layout)
            $content = <<<TWIG
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$pageTitle}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 2rem;
            background-color: #f8f9fa;
            color: #333;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>{$pageTitle}</h1>
        <p>This is the {$pageTitle} page.</p>
    </div>
</body>
</html>
TWIG;
        }

        if (file_put_contents($fullPath, $content) !== false) {
            $this->success("View template created: {$viewsPath}/{$templatePath}.twig");
            return true;
        }

        $this->error("Failed to create view template: {$viewsPath}/{$templatePath}.twig");
        return false;
    }

    /**
     * Generate a human-readable page title from template path
     * e.g., blog/blog-detail → Blog Detail
     * e.g., about → About
     */
    private function generatePageTitle(string $templatePath): string
    {
        $segments = explode('/', $templatePath);
        $lastSegment = end($segments);
        return ucwords(str_replace('-', ' ', $lastSegment));
    }

    // =========================================================================
    // Controller Method Generation
    // =========================================================================

    /**
     * Ensure the controller exists and has the required method
     *
     * @return bool|string false on failure, 'created_controller' if new controller, true if existing
     */
    private function ensureControllerMethod(string $namespace, string $controllerName, string $methodName, string $templatePath): bool|string
    {
        $controllerDir = $this->basePath('app/Controllers');
        $controllerPath = "{$controllerDir}/{$controllerName}.php";
        $createdController = false;

        if (!file_exists($controllerPath)) {
            $this->warning("Controller not found: {$controllerName}");

            if (!$this->confirm("Create controller '{$controllerName}'?", true)) {
                $this->error('Aborted. Cannot add route without a controller.');
                return false;
            }

            // Create the controller
            $this->ensureDirectory($controllerDir);
            $controllerContent = $this->generateNewController($namespace, $controllerName, $methodName, $templatePath);

            if (file_put_contents($controllerPath, $controllerContent) === false) {
                $this->error("Failed to create controller: {$controllerName}");
                return false;
            }

            $this->success("Controller created: app/Controllers/{$controllerName}.php");
            return 'created_controller';
        }

        // Controller exists — check if method already exists
        $existingContent = file_get_contents($controllerPath);

        if (preg_match('/function\s+' . preg_quote($methodName, '/') . '\s*\(/', $existingContent)) {
            $this->warning("Method '{$methodName}()' already exists in {$controllerName}. Skipping method creation.");
            return true;
        }

        // Append the new method to the existing controller
        $methodCode = $this->generateControllerMethod($methodName, $templatePath);
        $updatedContent = $this->appendMethodToClass($existingContent, $methodCode);

        if ($updatedContent === null) {
            $this->error("Could not append method to {$controllerName}. Please add the method manually.");
            $this->line('');
            $this->line("    public function {$methodName}(): string");
            $this->line("    {");
            $this->line("        return \$this->render('{$templatePath}');");
            $this->line("    }");
            return false;
        }

        if (file_put_contents($controllerPath, $updatedContent) !== false) {
            $this->success("Method '{$methodName}()' added to {$controllerName}");
            return true;
        }

        $this->error("Failed to write to controller: {$controllerName}");
        return false;
    }

    /**
     * Generate a brand new controller file with the given method
     */
    private function generateNewController(string $namespace, string $controllerName, string $methodName, string $templatePath): string
    {
        $controllerNamespace = $namespace . '\\Controllers';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$controllerNamespace};

use ZephyrPHP\Core\Controllers\Controller;

class {$controllerName} extends Controller
{
    public function {$methodName}(): string
    {
        return \$this->render('{$templatePath}');
    }
}
PHP;
    }

    /**
     * Generate a single controller method string
     */
    private function generateControllerMethod(string $methodName, string $templatePath): string
    {
        return <<<PHP

    public function {$methodName}(): string
    {
        return \$this->render('{$templatePath}');
    }
PHP;
    }

    /**
     * Append a method to an existing class by inserting before the last closing brace
     */
    private function appendMethodToClass(string $classContent, string $methodCode): ?string
    {
        // Find the last closing brace of the class
        $lastBracePos = strrpos($classContent, '}');

        if ($lastBracePos === false) {
            return null;
        }

        // Insert the method before the last closing brace
        $before = substr($classContent, 0, $lastBracePos);
        $after = substr($classContent, $lastBracePos);

        return rtrim($before) . "\n" . $methodCode . "\n" . $after;
    }

    // =========================================================================
    // Route Addition
    // =========================================================================

    /**
     * Check if a route with the given URI already exists
     */
    private function routeExists(string $uri): bool
    {
        $routesFile = $this->basePath('routes/web.php');

        if (!file_exists($routesFile)) {
            return false;
        }

        $content = file_get_contents($routesFile);

        // Check for the URI in single or double quotes
        return str_contains($content, "'{$uri}'") || str_contains($content, "\"{$uri}\"");
    }

    /**
     * Detect the routing style used in routes/web.php
     */
    private function detectRouteStyle(): string
    {
        $routesFile = $this->basePath('routes/web.php');

        if (file_exists($routesFile)) {
            $content = file_get_contents($routesFile);
            if (str_contains($content, 'Route::')) {
                return 'static';
            }
            if (str_contains($content, '$router->')) {
                return 'instance';
            }
        }

        // Default to static style
        return 'static';
    }

    /**
     * Ensure the routes/web.php file exists with basic boilerplate
     */
    private function ensureRoutesFile(): string
    {
        $routesFile = $this->basePath('routes/web.php');

        if (!file_exists($routesFile)) {
            $this->ensureDirectory(dirname($routesFile));

            $boilerplate = <<<'PHP'
<?php

/**
 * Web Routes
 *
 * Define your application routes here.
 */

use ZephyrPHP\Router\Route;

PHP;
            file_put_contents($routesFile, $boilerplate);
            $this->info('Created routes/web.php');
        }

        return $routesFile;
    }

    /**
     * Add a closure-based route to routes/web.php
     */
    private function addClosureRoute(string $uri, string $httpMethod, string $templatePath, string $routeName): bool
    {
        $routesFile = $this->ensureRoutesFile();
        $content = file_get_contents($routesFile);
        $style = $this->detectRouteStyle();

        if ($style === 'static') {
            $routeCode = "\n// {$this->generatePageTitle($templatePath)} page\n";
            $routeCode .= "Route::{$httpMethod}('{$uri}', function () {\n";
            $routeCode .= "    return view('{$templatePath}');\n";
            $routeCode .= "})->name('{$routeName}');";
        } else {
            $routeCode = "\n// {$this->generatePageTitle($templatePath)} page\n";
            $routeCode .= "\$router->{$httpMethod}('{$uri}', function () {\n";
            $routeCode .= "    return view('{$templatePath}');\n";
            $routeCode .= "});";
        }

        if (file_put_contents($routesFile, $content . $routeCode) !== false) {
            $this->success("Route added to routes/web.php");
            return true;
        }

        $this->error('Failed to write to routes/web.php');
        return false;
    }

    /**
     * Add a controller-based route to routes/web.php
     */
    private function addControllerRoute(string $uri, string $httpMethod, string $namespace, string $controllerName, string $methodName, string $routeName): bool
    {
        $routesFile = $this->ensureRoutesFile();
        $content = file_get_contents($routesFile);
        $style = $this->detectRouteStyle();

        $controllerClass = "{$namespace}\\Controllers\\{$controllerName}";

        // Add use import if not already present
        $content = $this->ensureUseImport($content, $controllerClass);

        if ($style === 'static') {
            $routeCode = "\n// {$this->generatePageTitle($this->uriToTemplatePath($uri))} page\n";
            $routeCode .= "Route::{$httpMethod}('{$uri}', [{$controllerName}::class, '{$methodName}'])->name('{$routeName}');";
        } else {
            $routeCode = "\n// {$this->generatePageTitle($this->uriToTemplatePath($uri))} page\n";
            $routeCode .= "\$router->{$httpMethod}('{$uri}', [{$controllerName}::class, '{$methodName}']);";
        }

        if (file_put_contents($routesFile, $content . $routeCode) !== false) {
            $this->success("Route added to routes/web.php");
            return true;
        }

        $this->error('Failed to write to routes/web.php');
        return false;
    }

    /**
     * Ensure a use import statement exists in the routes file
     */
    private function ensureUseImport(string $content, string $fullyQualifiedClass): string
    {
        $useStatement = "use {$fullyQualifiedClass};";

        // Already imported
        if (str_contains($content, $useStatement)) {
            return $content;
        }

        // Find the last use statement and insert after it
        if (preg_match('/^use\s+.+;$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Find the position of the last use statement
            $allUseStatements = [];
            preg_match_all('/^use\s+.+;$/m', $content, $allUseStatements, PREG_OFFSET_CAPTURE);

            if (!empty($allUseStatements[0])) {
                $lastUse = end($allUseStatements[0]);
                $insertPos = $lastUse[1] + strlen($lastUse[0]);
                $content = substr($content, 0, $insertPos) . "\n" . $useStatement . substr($content, $insertPos);
                return $content;
            }
        }

        // No use statements found — insert after <?php or opening comments
        if (preg_match('/^<\?php\s*\n(?:\/\*\*[\s\S]*?\*\/\s*\n)?/m', $content, $matches)) {
            $insertPos = strlen($matches[0]);
            $content = substr($content, 0, $insertPos) . "\n" . $useStatement . "\n" . substr($content, $insertPos);
        }

        return $content;
    }
}
