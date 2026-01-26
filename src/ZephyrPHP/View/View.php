<?php

declare(strict_types=1);

namespace ZephyrPHP\View;

use ZephyrPHP\Extensions\AssetExtension;
use ZephyrPHP\Extensions\CspExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;
use Closure;

/**
 * Zephyr View Manager
 *
 * A powerful, user-friendly templating system built on Twig.
 *
 * Features:
 * - Twig templating engine
 * - View composers for automatic data injection
 * - Shared global data
 * - Template namespacing
 * - View existence checking
 * - Custom functions and filters
 * - Fluent ViewBuilder interface
 *
 * @package ZephyrPHP
 * @author ZephyrPHP Team
 */
class View
{
    private static ?View $instance = null;
    protected Environment $twig;
    protected FilesystemLoader $loader;

    // View composers - callbacks that inject data into views
    protected static array $composers = [];

    // Shared data available to all views
    protected static array $shared = [];

    // View namespaces for organized templates
    protected array $namespaces = [];

    // Component paths
    protected static array $components = [];

    public function __construct()
    {
        $templateDir = BASE_PATH . ($_ENV["VIEWS_PATH"] ?? '/pages');
        $cacheDir = BASE_PATH . '/storage/compiled';
        $isProduction = ($_ENV['ENV'] ?? 'dev') === 'production';

        $this->loader = new FilesystemLoader($templateDir);
        $this->twig = new Environment($this->loader, [
            'cache' => $isProduction ? $cacheDir : false,
            'auto_reload' => !$isProduction,
            'debug' => !$isProduction,
            'strict_variables' => $isProduction,
            'autoescape' => 'html',
        ]);

        $this->twig->addExtension(new AssetExtension());
        $this->twig->addExtension(new CspExtension());

        // Only add debug extension in non-production
        if (!$isProduction) {
            $this->twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        // Register custom functions
        $this->registerCoreFunctions();

        // Add session as a global for easy access in templates
        $this->registerSessionGlobal();

        self::$instance = $this;
    }

    /**
     * Register session data as a Twig global
     * This allows templates to access session.flash.errors, session.flash._old_input, etc.
     */
    protected function registerSessionGlobal(): void
    {
        $this->twig->addGlobal('session', new SessionAccessor());
    }

    /**
     * Get the singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ========================================================================
    // RENDERING
    // ========================================================================

    /**
     * Render a template
     */
    public function render(string $template, array $data = []): string
    {
        $template = $this->resolveTemplate($template);

        // Merge shared data
        $data = array_merge(self::$shared, $data);

        // Run view composers
        $data = $this->runComposers($template, $data);

        return $this->twig->render($template, $data);
    }

    /**
     * Create a ViewBuilder for fluent interface
     */
    public function make(string $template): ViewBuilder
    {
        return new ViewBuilder($this, $template);
    }

    /**
     * Render a template to a string (alias for render)
     */
    public function toString(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    /**
     * Check if a template exists
     */
    public function exists(string $template): bool
    {
        $template = $this->resolveTemplate($template);
        return $this->loader->exists($template);
    }

    /**
     * Get the first existing template from a list
     */
    public function first(array $templates, array $data = []): string
    {
        foreach ($templates as $template) {
            if ($this->exists($template)) {
                return $this->render($template, $data);
            }
        }

        throw new \InvalidArgumentException(
            'None of the views [' . implode(', ', $templates) . '] exist.'
        );
    }

    /**
     * Render a template if it exists, otherwise return default
     */
    public function renderIfExists(string $template, array $data = [], string $default = ''): string
    {
        if ($this->exists($template)) {
            return $this->render($template, $data);
        }
        return $default;
    }

    /**
     * Render each item in a collection
     */
    public function each(string $template, array $data, string $itemVar, string $emptyTemplate = null): string
    {
        $result = '';

        if (empty($data)) {
            if ($emptyTemplate) {
                return $this->render($emptyTemplate);
            }
            return '';
        }

        foreach ($data as $key => $item) {
            $result .= $this->render($template, [
                $itemVar => $item,
                'key' => $key,
                'loop' => [
                    'index' => $key,
                    'first' => $key === array_key_first($data),
                    'last' => $key === array_key_last($data),
                ],
            ]);
        }

        return $result;
    }

    // ========================================================================
    // VIEW COMPOSERS
    // ========================================================================

    /**
     * Register a view composer
     *
     * Composers are callbacks that are called when a view is rendered,
     * allowing you to automatically inject data into specific views.
     *
     * @param string|array $views View name(s) or pattern(s)
     * @param Closure|string $callback Callback or class name
     */
    public static function composer(string|array $views, Closure|string $callback): void
    {
        $views = is_array($views) ? $views : [$views];

        foreach ($views as $view) {
            self::$composers[$view][] = $callback;
        }
    }

    /**
     * Register a creator (runs before composer)
     */
    public static function creator(string|array $views, Closure|string $callback): void
    {
        // Creators run before composers, so we prepend them
        $views = is_array($views) ? $views : [$views];

        foreach ($views as $view) {
            if (!isset(self::$composers[$view])) {
                self::$composers[$view] = [];
            }
            array_unshift(self::$composers[$view], $callback);
        }
    }

    /**
     * Run view composers for a template
     */
    protected function runComposers(string $template, array $data): array
    {
        // Create a view context
        $context = new ViewContext($template, $data);

        // Run exact match composers
        if (isset(self::$composers[$template])) {
            foreach (self::$composers[$template] as $composer) {
                $this->callComposer($composer, $context);
            }
        }

        // Run pattern match composers
        foreach (self::$composers as $pattern => $composers) {
            if ($pattern === $template) {
                continue;
            }

            // Support wildcard patterns like 'admin.*' or 'pages.users.*'
            if (str_contains($pattern, '*')) {
                $regex = str_replace('.', '\.', $pattern);
                $regex = str_replace('*', '.*', $regex);
                $templateName = str_replace(['/', '.twig'], ['.', ''], $template);

                if (preg_match("/^{$regex}$/", $templateName)) {
                    foreach ($composers as $composer) {
                        $this->callComposer($composer, $context);
                    }
                }
            }
        }

        return $context->getData();
    }

    /**
     * Call a composer callback or class
     */
    protected function callComposer(Closure|string $composer, ViewContext $context): void
    {
        if ($composer instanceof Closure) {
            $composer($context);
        } elseif (is_string($composer) && class_exists($composer)) {
            $instance = new $composer();
            if (method_exists($instance, 'compose')) {
                $instance->compose($context);
            }
        }
    }

    // ========================================================================
    // SHARED DATA
    // ========================================================================

    /**
     * Share data with all views
     */
    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                self::$shared[$k] = $v;
            }
        } else {
            self::$shared[$key] = $value;
        }
    }

    /**
     * Get shared data
     */
    public static function getShared(?string $key = null): mixed
    {
        if ($key === null) {
            return self::$shared;
        }
        return self::$shared[$key] ?? null;
    }

    /**
     * Clear shared data
     */
    public static function clearShared(): void
    {
        self::$shared = [];
    }

    // ========================================================================
    // NAMESPACES
    // ========================================================================

    /**
     * Add a namespace for template organization
     */
    public function addNamespace(string $namespace, string $path): self
    {
        $this->loader->addPath($path, $namespace);
        $this->namespaces[$namespace] = $path;
        return $this;
    }

    /**
     * Replace a namespace path
     */
    public function replaceNamespace(string $namespace, string $path): self
    {
        $this->loader->setPaths([$path], $namespace);
        $this->namespaces[$namespace] = $path;
        return $this;
    }

    /**
     * Get all namespaces
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    // ========================================================================
    // COMPONENTS
    // ========================================================================

    /**
     * Register a component path
     */
    public static function componentPath(string $namespace, string $path): void
    {
        self::$components[$namespace] = $path;
    }

    /**
     * Render a component
     */
    public function component(string $name, array $data = [], array $slots = []): string
    {
        // Support namespaced components like 'ui.button' or 'forms.input'
        $templatePath = str_replace('.', '/', $name);
        $template = "components/{$templatePath}";

        // Add slots to data
        $data['slot'] = $slots['default'] ?? '';
        $data['slots'] = $slots;

        return $this->render($template, $data);
    }

    // ========================================================================
    // GLOBALS, FUNCTIONS & FILTERS
    // ========================================================================

    /**
     * Add a global variable
     */
    public function addGlobal(string $name, $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    /**
     * Add multiple globals
     */
    public function addGlobals(array $globals): void
    {
        foreach ($globals as $name => $value) {
            $this->twig->addGlobal($name, $value);
        }
    }

    /**
     * Add a custom function
     */
    public function addFunction(string $name, callable $function, array $options = []): void
    {
        $this->twig->addFunction(new TwigFunction($name, $function, $options));
    }

    /**
     * Add a custom filter
     */
    public function addFilter(string $name, callable $filter, array $options = []): void
    {
        $this->twig->addFilter(new TwigFilter($name, $filter, $options));
    }

    /**
     * Register core functions
     */
    protected function registerCoreFunctions(): void
    {
        // Component rendering
        $this->addFunction('component', [$this, 'component'], ['is_safe' => ['html']]);

        // Route helpers
        $this->addFunction('route', function (string $name, array $params = []) {
            return route($name, $params);
        });

        $this->addFunction('current_route', function () {
            return \ZephyrPHP\Router\Route::currentRouteName();
        });

        $this->addFunction('route_is', function (string ...$patterns) {
            return \ZephyrPHP\Router\Route::is(...$patterns);
        });

        // Request helpers
        $this->addFunction('request', function (?string $key = null, $default = null) {
            return request($key, $default);
        });

        $this->addFunction('old', function (string $key, $default = '') {
            return old($key, $default);
        });

        // Session helpers
        $this->addFunction('session', function (?string $key = null, $default = null) {
            return session($key, $default);
        });

        $this->addFunction('flash', function (string $key, $default = null) {
            return flash($key, $default);
        });

        // CSRF helpers
        $this->addFunction('csrf_token', function () {
            return csrf_token();
        }, ['is_safe' => ['html']]);

        $this->addFunction('csrf_field', function () {
            return csrf_field();
        }, ['is_safe' => ['html']]);

        // URL helpers
        $this->addFunction('url', function (string $path = '') {
            return url($path);
        });

        $this->addFunction('asset', function (string $path, array $options = []) {
            return asset($path, $options);
        });

        // Auth helpers (if available)
        $this->addFunction('auth', function () {
            return function_exists('auth') ? auth() : null;
        });

        // Config helper
        $this->addFunction('config', function (string $key, $default = null) {
            return function_exists('config') ? config($key, $default) : $default;
        });

        // Environment helper
        $this->addFunction('env', function (string $key, $default = null) {
            return env($key, $default);
        });

        // Translation helper (placeholder)
        $this->addFunction('__', function (string $key, array $replace = []) {
            return function_exists('__') ? __($key, $replace) : $key;
        });

        $this->addFunction('trans', function (string $key, array $replace = []) {
            return function_exists('__') ? __($key, $replace) : $key;
        });

        // Dump for debugging
        $this->addFunction('dd', function (...$vars) {
            dd(...$vars);
        });

        // Class helpers
        $this->addFunction('class_list', function (array $classes) {
            $result = [];
            foreach ($classes as $class => $condition) {
                if (is_numeric($class)) {
                    $result[] = $condition;
                } elseif ($condition) {
                    $result[] = $class;
                }
            }
            return implode(' ', $result);
        });

        // Date formatting
        $this->addFilter('relative_time', function ($date) {
            if (is_string($date)) {
                $date = new \DateTime($date);
            }
            $now = new \DateTime();
            $diff = $now->diff($date);

            if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
            if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
            if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
            if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
            if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
            return 'just now';
        });

        // String helpers
        $this->addFilter('limit', function (string $value, int $limit = 100, string $end = '...') {
            if (mb_strlen($value) <= $limit) {
                return $value;
            }
            return mb_substr($value, 0, $limit) . $end;
        });

        $this->addFilter('slug', function (string $value) {
            return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)));
        });

        // Number formatting
        $this->addFilter('money', function ($value, string $currency = '$', int $decimals = 2) {
            return $currency . number_format((float)$value, $decimals);
        });

        $this->addFilter('bytes', function (int $bytes, int $precision = 2) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
        });
    }

    // ========================================================================
    // UTILITY
    // ========================================================================

    /**
     * Resolve template path
     */
    protected function resolveTemplate(string $template): string
    {
        // Handle namespace syntax (e.g., 'admin::dashboard')
        if (str_contains($template, '::')) {
            [$namespace, $template] = explode('::', $template, 2);
            $template = "@{$namespace}/{$template}";
        }

        // Convert dot notation to path (e.g., 'pages.home' -> 'pages/home')
        $template = str_replace('.', '/', $template);

        // Add extension if missing
        if (!str_ends_with($template, '.twig')) {
            $template .= '.twig';
        }

        return $template;
    }

    /**
     * Get the Twig environment
     */
    public function getEngine(): Environment
    {
        return $this->twig;
    }

    /**
     * Clear compiled templates
     */
    public function clearCompiled(): void
    {
        $cacheDir = BASE_PATH . '/storage/compiled';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

/**
 * View Context
 *
 * Passed to view composers to allow data injection.
 */
class ViewContext
{
    private string $template;
    private array $data;

    public function __construct(string $template, array $data)
    {
        $this->template = $template;
        $this->data = $data;
    }

    /**
     * Get the template name
     */
    public function name(): string
    {
        return $this->template;
    }

    /**
     * Add data to the view
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }

    /**
     * Get data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get a specific data value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if data exists
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}

/**
 * View Builder
 *
 * Provides fluent interface for building views.
 */
class ViewBuilder
{
    private View $view;
    private string $template;
    private array $data = [];

    public function __construct(View $view, string $template)
    {
        $this->view = $view;
        $this->template = $template;
    }

    /**
     * Add data to the view
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }

    /**
     * Add data using magic method
     */
    public function __call(string $method, array $args): self
    {
        if (str_starts_with($method, 'with')) {
            $key = lcfirst(substr($method, 4));
            $this->data[$key] = $args[0] ?? null;
            return $this;
        }

        throw new \BadMethodCallException("Method [{$method}] does not exist.");
    }

    /**
     * Render the view
     */
    public function render(): string
    {
        return $this->view->render($this->template, $this->data);
    }

    /**
     * Convert to string (renders the view)
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Get the template name
     */
    public function name(): string
    {
        return $this->template;
    }

    /**
     * Get the data
     */
    public function getData(): array
    {
        return $this->data;
    }
}
