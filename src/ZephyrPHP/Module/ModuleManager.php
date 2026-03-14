<?php

declare(strict_types=1);

namespace ZephyrPHP\Module;

use ZephyrPHP\Container\Container;
use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Event\Events\ModuleBooting;
use ZephyrPHP\Event\Events\ModuleBooted;
use ZephyrPHP\Hook\HookManager;

/**
 * Module Manager
 *
 * Handles loading, checking, and managing optional framework modules.
 * Allows users to include only the features they need.
 *
 * Modules can be:
 * - Core modules (included in framework)
 * - Standalone packages (zephyrphp/database, zephyrphp/auth, etc.)
 */
class ModuleManager
{
    private static ?ModuleManager $instance = null;

    /** @var array<string, object> Loaded module service providers */
    private array $modules = [];

    /** @var array<string, bool> Module enabled status */
    private array $enabled = [];

    /** @var array<string, array> Available modules with their service providers */
    private array $available = [];

    /** @var bool Whether modules have been booted */
    private bool $booted = false;

    private Container $container;

    private function __construct()
    {
        $this->container = Container::getInstance();
        $this->discoverModules();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Discover available modules from installed packages and core
     */
    private function discoverModules(): void
    {
        // Core modules (always available, part of framework)
        $this->available = [
            'session' => [
                'provider' => null, // Built into core
                'dependencies' => [],
            ],
            'validation' => [
                'provider' => null, // Built into core
                'dependencies' => [],
            ],
            'view' => [
                'provider' => null, // Built into core
                'dependencies' => [],
            ],
        ];

        // Standalone module packages
        $modulePackages = [
            'database' => [
                'provider' => \ZephyrPHP\Database\DatabaseServiceProvider::class,
                'dependencies' => [],
            ],
            'api' => [
                'provider' => \ZephyrPHP\Api\ApiServiceProvider::class,
                'dependencies' => [],
            ],
            'auth' => [
                'provider' => \ZephyrPHP\Auth\AuthServiceProvider::class,
                'dependencies' => ['session'],
            ],
            'authorization' => [
                'provider' => \ZephyrPHP\Authorization\AuthorizationServiceProvider::class,
                'dependencies' => ['auth'],
            ],
            'cache' => [
                'provider' => \ZephyrPHP\Cache\CacheServiceProvider::class,
                'dependencies' => [],
            ],
            'mail' => [
                'provider' => \ZephyrPHP\Mail\MailServiceProvider::class,
                'dependencies' => [],
            ],
            'queue' => [
                'provider' => \ZephyrPHP\Queue\QueueServiceProvider::class,
                'dependencies' => [],
            ],
            'cms' => [
                'provider' => \ZephyrPHP\Cms\CmsServiceProvider::class,
                'dependencies' => ['database', 'auth', 'authorization'],
            ],
        ];

        // Check which packages are installed
        foreach ($modulePackages as $name => $config) {
            if ($config['provider'] === null || class_exists($config['provider'])) {
                $this->available[$name] = $config;
            }
        }
    }

    /**
     * Load modules from configuration
     *
     * @param array<string, bool|array> $config Module configuration
     */
    public function loadFromConfig(array $config): void
    {
        foreach ($config as $name => $settings) {
            if ($settings === true || (is_array($settings) && ($settings['enabled'] ?? true))) {
                if (!isset($this->available[$name])) {
                    // Module enabled in config but not installed — skip gracefully
                    error_log("[ZephyrPHP] Module '{$name}' is enabled in config but not installed. Skipping.");
                    continue;
                }
                $this->enable($name);
            }
        }
    }

    /**
     * Enable a module
     */
    public function enable(string $name): self
    {
        if (!isset($this->available[$name])) {
            throw new ModuleNotFoundException("Module '{$name}' is not available. Install it first with: php craftsman add {$name}");
        }

        // Check and enable dependencies first
        $moduleConfig = $this->available[$name];
        $dependencies = $moduleConfig['dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            if (!$this->isEnabled($dependency)) {
                $this->enable($dependency);
            }
        }

        $this->enabled[$name] = true;

        return $this;
    }

    /**
     * Disable a module
     */
    public function disable(string $name): self
    {
        // Check if other enabled modules depend on this one
        foreach ($this->enabled as $enabledModule => $status) {
            if (!$status || $enabledModule === $name) {
                continue;
            }

            $moduleConfig = $this->available[$enabledModule] ?? [];
            $dependencies = $moduleConfig['dependencies'] ?? [];

            if (in_array($name, $dependencies, true)) {
                throw new ModuleDependencyException(
                    "Cannot disable '{$name}': module '{$enabledModule}' depends on it."
                );
            }
        }

        $this->enabled[$name] = false;

        return $this;
    }

    /**
     * Check if a module is enabled
     */
    public function isEnabled(string $name): bool
    {
        return $this->enabled[$name] ?? false;
    }

    /**
     * Check if a module is available
     */
    public function isAvailable(string $name): bool
    {
        return isset($this->available[$name]);
    }

    /**
     * Check if a module is loaded (enabled and booted)
     */
    public function isLoaded(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * Boot all enabled modules
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Sort modules by dependencies
        $sorted = $this->sortByDependencies();

        foreach ($sorted as $name) {
            if (!$this->isEnabled($name)) {
                continue;
            }

            $moduleConfig = $this->available[$name] ?? [];
            $providerClass = $moduleConfig['provider'] ?? null;

            // Skip core modules without providers (they're built-in)
            if ($providerClass === null) {
                $this->modules[$name] = new \stdClass(); // Placeholder
                continue;
            }

            // Check if provider class exists
            if (!class_exists($providerClass)) {
                continue;
            }

            // Create provider instance
            $provider = new $providerClass();

            // Fire module.booting event
            $events = EventDispatcher::getInstance();
            $hooks = HookManager::getInstance();
            $events->dispatch(new ModuleBooting($name, $providerClass));
            $hooks->doAction('module.booting', $name, $providerClass);

            // Register services
            if (method_exists($provider, 'register')) {
                $provider->register($this->container);
            }

            // Register module in container
            $this->container->instance("module.{$name}", $provider);

            // Boot the module
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }

            $this->modules[$name] = $provider;

            // Fire module.booted event
            $events->dispatch(new ModuleBooted($name, $provider));
            $hooks->doAction('module.booted', $name, $provider);
        }

        $this->booted = true;
    }

    /**
     * Sort modules by dependencies (topological sort)
     *
     * @return string[]
     */
    private function sortByDependencies(): array
    {
        $sorted = [];
        $visited = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$visited): void {
            if (isset($visited[$name])) {
                return;
            }

            $visited[$name] = true;

            if (isset($this->available[$name])) {
                $moduleConfig = $this->available[$name];
                $dependencies = $moduleConfig['dependencies'] ?? [];

                foreach ($dependencies as $dep) {
                    $visit($dep);
                }
            }

            $sorted[] = $name;
        };

        foreach (array_keys($this->enabled) as $name) {
            if ($this->enabled[$name]) {
                $visit($name);
            }
        }

        return $sorted;
    }

    /**
     * Get a loaded module instance
     */
    public function get(string $name): ?object
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * Get all loaded modules
     *
     * @return array<string, object>
     */
    public function getLoaded(): array
    {
        return $this->modules;
    }

    /**
     * Get all available module names
     *
     * @return string[]
     */
    public function getAvailable(): array
    {
        return array_keys($this->available);
    }

    /**
     * Get all enabled module names
     *
     * @return string[]
     */
    public function getEnabled(): array
    {
        return array_keys(array_filter($this->enabled));
    }

    /**
     * Require a module (throws exception if not enabled)
     *
     * @throws ModuleNotEnabledException
     */
    public function require(string $name): object
    {
        if (!$this->isLoaded($name)) {
            throw new ModuleNotEnabledException(
                "Module '{$name}' is required but not enabled. " .
                "Enable it in config/modules.php or run: php craftsman module:enable {$name}"
            );
        }

        return $this->modules[$name];
    }

    /**
     * Check multiple modules and return which are missing
     *
     * @param string[] $names
     * @return string[] Missing module names
     */
    public function checkRequired(array $names): array
    {
        $missing = [];

        foreach ($names as $name) {
            if (!$this->isEnabled($name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Get module information
     *
     * @return array{name: string, dependencies: string[], enabled: bool, loaded: bool}|null
     */
    public function info(string $name): ?array
    {
        if (!isset($this->available[$name])) {
            return null;
        }

        $moduleConfig = $this->available[$name];

        return [
            'name' => ucfirst($name),
            'dependencies' => $moduleConfig['dependencies'] ?? [],
            'enabled' => $this->isEnabled($name),
            'loaded' => $this->isLoaded($name),
            'provider' => $moduleConfig['provider'] ?? null,
        ];
    }

    /**
     * Reset the module manager (mainly for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
