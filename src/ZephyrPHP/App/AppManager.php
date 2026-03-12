<?php

declare(strict_types=1);

namespace ZephyrPHP\App;

use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Event\Events\AppEnabled;
use ZephyrPHP\Event\Events\AppDisabled;
use ZephyrPHP\Hook\HookManager;

/**
 * App Manager — discovers, loads, enables, and disables marketplace apps.
 *
 * Apps live in {BASE_PATH}/apps/{slug}/ and contain an app.json manifest.
 * The registry (enabled/disabled state) is stored in {BASE_PATH}/apps/registry.json.
 *
 * Security:
 * - Only loads main class files from verified app directories
 * - Validates app.json schema before loading
 * - Slug format enforced (lowercase alphanumeric + hyphens/underscores)
 * - No eval or dynamic code generation
 */
class AppManager
{
    private static ?AppManager $instance = null;

    /**
     * Base directory where apps are installed.
     */
    private string $appsPath;

    /**
     * Registry of app states: slug → {enabled: bool, installed_at: string}
     * @var array<string, array{enabled: bool, installed_at: string}>
     */
    private array $registry = [];

    /**
     * Loaded app instances (only enabled apps).
     * @var array<string, MarketplaceApp>
     */
    private array $apps = [];

    /**
     * Whether apps have been booted.
     */
    private bool $booted = false;

    public function __construct(?string $appsPath = null)
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $this->appsPath = $appsPath ?? $basePath . '/apps';
        $this->loadRegistry();
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    // ========================================================================
    // DISCOVERY & LOADING
    // ========================================================================

    /**
     * Discover and load all enabled apps.
     * Called during application boot.
     */
    public function discoverAndLoad(): void
    {
        if (!is_dir($this->appsPath)) {
            return;
        }

        foreach ($this->registry as $slug => $state) {
            if (!$state['enabled']) {
                continue;
            }

            $app = $this->loadApp($slug);
            if ($app) {
                $this->apps[$slug] = $app;
            }
        }
    }

    /**
     * Register all loaded apps (phase 1 of lifecycle).
     */
    public function registerAll(): void
    {
        foreach ($this->apps as $app) {
            try {
                $app->register();
            } catch (\Throwable $e) {
                error_log("App '{$app->getSlug()}' register() failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Boot all loaded apps (phase 2 of lifecycle).
     */
    public function bootAll(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->apps as $app) {
            try {
                $app->boot();

                // Load route files registered during boot
                foreach ($app->getRouteFiles() as $routeFile) {
                    require $routeFile;
                }

                // Run pending migrations
                $this->runMigrations($app);
            } catch (\Throwable $e) {
                error_log("App '{$app->getSlug()}' boot() failed: " . $e->getMessage());
            }
        }

        $this->booted = true;
    }

    /**
     * Load a single app from its directory.
     */
    private function loadApp(string $slug): ?MarketplaceApp
    {
        $slug = $this->sanitizeSlug($slug);
        $appPath = $this->appsPath . '/' . $slug;

        if (!is_dir($appPath)) {
            return null;
        }

        // Read and validate app.json
        $configFile = $appPath . '/app.json';
        if (!file_exists($configFile)) {
            return null;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!is_array($config) || empty($config['name'])) {
            return null;
        }

        // Determine main class
        $mainClass = $config['main'] ?? null;
        if (!$mainClass) {
            return null;
        }

        // Load the main class file
        $mainFile = $appPath . '/src/' . basename(str_replace('\\', '/', $mainClass)) . '.php';
        if (!file_exists($mainFile)) {
            return null;
        }

        // Verify path is within app directory (prevent traversal)
        $realApp = realpath($appPath);
        $realMain = realpath($mainFile);
        if (!$realApp || !$realMain || !str_starts_with($realMain, $realApp)) {
            return null;
        }

        require_once $mainFile;

        // Resolve fully qualified class name
        $namespace = $config['namespace'] ?? '';
        $fqcn = $namespace ? $namespace . '\\' . $mainClass : $mainClass;

        if (!class_exists($fqcn)) {
            return null;
        }

        $instance = new $fqcn();
        if (!$instance instanceof MarketplaceApp) {
            return null;
        }

        $instance->setContext($slug, $appPath, $config);
        return $instance;
    }

    // ========================================================================
    // ENABLE / DISABLE
    // ========================================================================

    /**
     * Enable an installed app.
     *
     * @return array{success: bool, error?: string}
     */
    public function enable(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);

        if (!isset($this->registry[$slug])) {
            return ['success' => false, 'error' => "App '{$slug}' is not installed."];
        }

        if ($this->registry[$slug]['enabled']) {
            return ['success' => true]; // Already enabled
        }

        $this->registry[$slug]['enabled'] = true;
        $this->saveRegistry();

        EventDispatcher::getInstance()->dispatch(new AppEnabled($slug));
        HookManager::getInstance()->doAction('app.enabled', $slug);

        return ['success' => true];
    }

    /**
     * Disable an installed app.
     *
     * @return array{success: bool, error?: string}
     */
    public function disable(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);

        if (!isset($this->registry[$slug])) {
            return ['success' => false, 'error' => "App '{$slug}' is not installed."];
        }

        if (!$this->registry[$slug]['enabled']) {
            return ['success' => true]; // Already disabled
        }

        $this->registry[$slug]['enabled'] = false;
        $this->saveRegistry();

        // Remove from loaded apps
        unset($this->apps[$slug]);

        EventDispatcher::getInstance()->dispatch(new AppDisabled($slug));
        HookManager::getInstance()->doAction('app.disabled', $slug);

        return ['success' => true];
    }

    // ========================================================================
    // REGISTRY MANAGEMENT
    // ========================================================================

    /**
     * Add an app to the registry (called by AppInstaller after extraction).
     */
    public function addToRegistry(string $slug, bool $enabled = false): void
    {
        $this->registry[$slug] = [
            'enabled' => $enabled,
            'installed_at' => date('Y-m-d H:i:s'),
        ];
        $this->saveRegistry();
    }

    /**
     * Remove an app from the registry.
     */
    public function removeFromRegistry(string $slug): void
    {
        unset($this->registry[$slug]);
        unset($this->apps[$slug]);
        $this->saveRegistry();
    }

    /**
     * Check if an app is enabled.
     */
    public function isEnabled(string $slug): bool
    {
        return ($this->registry[$slug]['enabled'] ?? false) === true;
    }

    /**
     * Check if an app is installed.
     */
    public function isInstalled(string $slug): bool
    {
        return isset($this->registry[$slug]);
    }

    // ========================================================================
    // LISTING
    // ========================================================================

    /**
     * List all installed apps with metadata.
     *
     * @return array<string, array{slug: string, name: string, description: string, version: string, enabled: bool, installed_at: string, config: array}>
     */
    public function list(): array
    {
        $result = [];

        foreach ($this->registry as $slug => $state) {
            $appPath = $this->appsPath . '/' . $slug;
            $configFile = $appPath . '/app.json';

            $config = [];
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
            }

            $result[$slug] = [
                'slug' => $slug,
                'name' => $config['name'] ?? $slug,
                'description' => $config['description'] ?? '',
                'version' => $config['version'] ?? '0.0.0',
                'author' => $config['author'] ?? '',
                'enabled' => $state['enabled'],
                'installed_at' => $state['installed_at'] ?? '',
                'config' => $config,
            ];
        }

        return $result;
    }

    /**
     * Get a loaded app instance.
     */
    public function getApp(string $slug): ?MarketplaceApp
    {
        return $this->apps[$slug] ?? null;
    }

    /**
     * Get the apps base directory path.
     */
    public function getAppsPath(): string
    {
        return $this->appsPath;
    }

    // ========================================================================
    // MIGRATIONS
    // ========================================================================

    /**
     * Run pending migrations for an app.
     */
    private function runMigrations(MarketplaceApp $app): void
    {
        foreach ($app->getMigrationPaths() as $migrationsDir) {
            if (!is_dir($migrationsDir)) {
                continue;
            }

            $ranFile = $migrationsDir . '/.migrations_ran';
            $ran = [];
            if (file_exists($ranFile)) {
                $ran = json_decode(file_get_contents($ranFile), true) ?: [];
            }

            // Get migration files sorted by name
            $files = glob($migrationsDir . '/*.php');
            if (!$files) {
                continue;
            }
            sort($files);

            foreach ($files as $file) {
                $name = basename($file);
                if (in_array($name, $ran, true)) {
                    continue;
                }

                // Verify file is within migrations dir
                $realMig = realpath($migrationsDir);
                $realFile = realpath($file);
                if (!$realMig || !$realFile || !str_starts_with($realFile, $realMig)) {
                    continue;
                }

                try {
                    $migration = require $file;
                    if (is_callable($migration)) {
                        $migration();
                    } elseif (is_object($migration) && method_exists($migration, 'up')) {
                        $migration->up();
                    }
                    $ran[] = $name;
                } catch (\Throwable $e) {
                    error_log("App '{$app->getSlug()}' migration '{$name}' failed: " . $e->getMessage());
                    break; // Stop further migrations on failure
                }
            }

            file_put_contents($ranFile, json_encode($ran, JSON_PRETTY_PRINT));
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function loadRegistry(): void
    {
        $registryFile = $this->appsPath . '/registry.json';
        if (file_exists($registryFile)) {
            $data = json_decode(file_get_contents($registryFile), true);
            $this->registry = is_array($data) ? $data : [];
        }
    }

    private function saveRegistry(): void
    {
        if (!is_dir($this->appsPath)) {
            mkdir($this->appsPath, 0755, true);
        }

        file_put_contents(
            $this->appsPath . '/registry.json',
            json_encode($this->registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Sanitize an app slug.
     */
    private function sanitizeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($slug)));
        if (empty($slug)) {
            throw new \InvalidArgumentException('Invalid app slug.');
        }
        return $slug;
    }
}
