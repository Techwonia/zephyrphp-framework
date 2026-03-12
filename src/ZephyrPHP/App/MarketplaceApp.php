<?php

declare(strict_types=1);

namespace ZephyrPHP\App;

use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Hook\HookManager;
use ZephyrPHP\Cms\Services\SidebarManager;

/**
 * Base Marketplace App class — all ZIP-installed apps extend this.
 *
 * Provides a controlled API surface for apps to:
 * - Register sidebar items, routes, views, and migrations
 * - Listen to events and hooks
 * - Store and retrieve settings
 * - Declare assets for publishing
 *
 * Apps have a well-defined lifecycle:
 *   install()  → called once when the ZIP is first installed
 *   register() → called on every request (bind services)
 *   boot()     → called after all apps are registered (use services)
 *   uninstall()→ called once when the app is removed
 */
abstract class MarketplaceApp
{
    /**
     * The app slug (set by AppManager from app.json).
     */
    protected string $slug = '';

    /**
     * Absolute path to the app directory.
     */
    protected string $path = '';

    /**
     * App configuration from app.json.
     */
    protected array $config = [];

    /**
     * Route files to load on boot.
     * @var string[]
     */
    private array $routeFiles = [];

    /**
     * Migration directories to register.
     * @var string[]
     */
    private array $migrationPaths = [];

    /**
     * Set by AppManager before register/boot.
     */
    public function setContext(string $slug, string $path, array $config): void
    {
        $this->slug = $slug;
        $this->path = rtrim($path, '/\\');
        $this->config = $config;
    }

    /**
     * Register bindings, services. Called on every request.
     */
    public function register(): void
    {
        // Override in app
    }

    /**
     * Boot the app — add sidebar items, routes, hooks.
     * Called after all apps are registered.
     */
    public function boot(): void
    {
        // Override in app
    }

    /**
     * Called once when the app is first installed.
     * Use for initial setup (seeding data, creating config files).
     */
    public function install(): void
    {
        // Override in app
    }

    /**
     * Called once when the app is removed.
     * Use for cleanup (dropping tables, removing files).
     */
    public function uninstall(): void
    {
        // Override in app
    }

    // ========================================================================
    // SIDEBAR HELPERS
    // ========================================================================

    /**
     * Add a sidebar item under the "Apps" section.
     */
    protected function sidebar(string $label, string $url, string $icon = 'puzzle'): void
    {
        $sidebar = SidebarManager::getInstance();
        $sidebar->addItem('apps', [
            'id' => 'app-' . $this->slug,
            'label' => $label,
            'url' => $url,
            'icon' => $icon,
            'match' => 'prefix:' . $url,
        ]);
    }

    // ========================================================================
    // ROUTE HELPERS
    // ========================================================================

    /**
     * Register a route file to be loaded on boot.
     *
     * @param string $relativePath Path relative to the app directory
     */
    protected function routes(string $relativePath): void
    {
        $fullPath = $this->path . '/' . ltrim($relativePath, '/');
        if (file_exists($fullPath)) {
            $this->routeFiles[] = $fullPath;
        }
    }

    /**
     * Get registered route files — called by AppManager.
     *
     * @return string[]
     */
    public function getRouteFiles(): array
    {
        return $this->routeFiles;
    }

    // ========================================================================
    // MIGRATION HELPERS
    // ========================================================================

    /**
     * Register a migration directory.
     *
     * @param string $relativePath Path relative to app directory
     */
    protected function migrations(string $relativePath): void
    {
        $fullPath = $this->path . '/' . ltrim($relativePath, '/');
        if (is_dir($fullPath)) {
            $this->migrationPaths[] = $fullPath;
        }
    }

    /**
     * Get registered migration paths — called by AppManager.
     *
     * @return string[]
     */
    public function getMigrationPaths(): array
    {
        return $this->migrationPaths;
    }

    // ========================================================================
    // VIEW HELPERS
    // ========================================================================

    /**
     * Register a Twig namespace for this app's views.
     * Templates will be accessible as @{slug}/template-name
     */
    protected function views(string $relativePath = 'views'): void
    {
        $viewsPath = $this->path . '/' . ltrim($relativePath, '/');
        if (is_dir($viewsPath) && class_exists(\ZephyrPHP\View\View::class)) {
            \ZephyrPHP\View\View::getInstance()->addNamespace($this->slug, $viewsPath);
        }
    }

    // ========================================================================
    // EVENT & HOOK HELPERS
    // ========================================================================

    /**
     * Listen to an event class.
     */
    protected function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        EventDispatcher::getInstance()->listen($eventClass, $listener, $priority);
    }

    /**
     * Register an action hook.
     */
    protected function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        HookManager::getInstance()->addAction($hook, $callback, $priority);
    }

    /**
     * Register a filter hook.
     */
    protected function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        HookManager::getInstance()->addFilter($hook, $callback, $priority);
    }

    // ========================================================================
    // SETTINGS HELPERS
    // ========================================================================

    /**
     * Get an app setting value from its config.json.
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        $settingsFile = $this->path . '/config.json';
        if (!file_exists($settingsFile)) {
            return $default;
        }

        $settings = json_decode(file_get_contents($settingsFile), true);
        return $settings[$key] ?? $default;
    }

    /**
     * Save an app setting to config.json.
     */
    protected function saveSetting(string $key, mixed $value): void
    {
        $settingsFile = $this->path . '/config.json';
        $settings = [];

        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }

        $settings[$key] = $value;
        file_put_contents(
            $settingsFile,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // ========================================================================
    // ACCESSORS
    // ========================================================================

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getName(): string
    {
        return $this->config['name'] ?? $this->slug;
    }

    public function getVersion(): string
    {
        return $this->config['version'] ?? '0.0.0';
    }

    public function getDescription(): string
    {
        return $this->config['description'] ?? '';
    }
}
