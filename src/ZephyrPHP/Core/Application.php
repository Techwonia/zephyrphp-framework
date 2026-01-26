<?php

declare(strict_types=1);

namespace ZephyrPHP\Core;

use ZephyrPHP\Router\Route;
use ZephyrPHP\Security\Headers;
use ZephyrPHP\Exception\Handler;
use ZephyrPHP\Session\Session;
use ZephyrPHP\Log\LogManager;
use ZephyrPHP\Asset\Asset;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Container\Container;
use ZephyrPHP\Module\ModuleManager;

class Application
{
    private static ?Application $instance = null;
    private bool $booted = false;
    private ?Session $session = null;
    private ?LogManager $logger = null;
    private ?Container $container = null;
    private ?ModuleManager $modules = null;

    public function __construct()
    {
        self::$instance = $this;
        $this->bootstrap();
    }

    protected function bootstrap(): void
    {
        // Initialize container first
        $this->container = Container::getInstance();
        $this->registerCoreBindings();

        Handler::register();

        $this->configureErrorHandling();

        // Initialize module manager and load modules
        $this->modules = ModuleManager::getInstance();
        $this->loadModules();

        // Start session only if module is enabled (or for backwards compatibility)
        if ($this->modules->isEnabled('session')) {
            $this->session = Session::getInstance();
            $this->session->start();
        }

        $this->logger = LogManager::getInstance();

        Headers::apply();

        $this->configureAssets();

        $this->registerRoutes();

        // Age flash data only if session is available
        if ($this->session !== null) {
            $this->session->ageFlashData();
        }

        $this->booted = true;

        $this->logger->info('Application booted', [
            'environment' => $this->getEnvironment(),
            'debug' => $this->isDebug(),
            'modules' => $this->modules->getEnabled(),
        ]);
    }

    /**
     * Load and boot enabled modules
     */
    protected function loadModules(): void
    {
        // Load module configuration
        $modulesConfig = Config::get('modules', []);

        // If no config exists, enable only core modules by default
        if (empty($modulesConfig)) {
            $modulesConfig = [
                'session' => true,
                'validation' => true,
                'view' => true,
            ];
        }

        // Load modules from config
        $this->modules->loadFromConfig($modulesConfig);

        // Boot all enabled modules
        $this->modules->boot();
    }

    /**
     * Register core service bindings
     */
    protected function registerCoreBindings(): void
    {
        // Register the application instance
        $this->container->instance('app', $this);
        $this->container->instance(Application::class, $this);

        // Register core services as singletons
        $this->container->singleton(Session::class, fn() => Session::getInstance());
        $this->container->singleton(LogManager::class, fn() => LogManager::getInstance());
        $this->container->singleton(\ZephyrPHP\Core\Http\Request::class, fn() => \ZephyrPHP\Core\Http\Request::getInstance());
    }

    protected function configureErrorHandling(): void
    {
        $isProduction = $this->isProduction();
        $debug = $this->isDebug();

        if ($isProduction || !$debug) {
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }

        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
    }

    protected function configureAssets(): void
    {
        $config = Config::get('assets', []);

        Asset::configure([
            'base_url' => $_ENV['APP_URL'] ?? '',
            'base_path' => $config['public_path'] ?? (defined('PUBLIC_PATH') ? PUBLIC_PATH : null),
            'cdn_url' => $config['cdn_url'] ?? null,
            'cdn_enabled' => $config['cdn_enabled'] ?? true,
            'version_strategy' => $config['version_strategy'] ?? 'timestamp',
            'global_version' => $config['global_version'] ?? '1.0.0',
            'integrity' => $config['integrity'] ?? false,
            'minify' => $config['minify'] ?? false,
            'environment' => $config['environment'] ?? ($_ENV['ENV'] ?? 'development'),
            'assets_prefix' => $config['assets_prefix'] ?? 'assets',
        ]);

        // Load manifest if configured
        if (!empty($config['manifest'])) {
            Asset::loadManifest($config['manifest']);
        }

        // Register asset collections
        if (!empty($config['collections'])) {
            foreach ($config['collections'] as $name => $assets) {
                Asset::collection($name, $assets);
            }
        }
    }

    protected function registerRoutes(): void
    {
        $routesPath = BASE_PATH . '/routes/web.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        } else {
            throw new \RuntimeException("Routes file not found: $routesPath");
        }

        $apiRoutesPath = BASE_PATH . '/routes/api.php';
        if (file_exists($apiRoutesPath)) {
            require $apiRoutesPath;
        }
    }

    public function run(): void
    {
        try {
            Route::dispatch();
        } catch (\Throwable $e) {
            Handler::getInstance()->handleException($e);
        }
    }

    public static function getInstance(): ?Application
    {
        return self::$instance;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function isProduction(): bool
    {
        return $this->getEnvironment() === 'production';
    }

    public function isDebug(): bool
    {
        return filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function getEnvironment(): string
    {
        return $_ENV['ENV'] ?? 'dev';
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getLogger(): LogManager
    {
        return $this->logger;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get the module manager instance
     */
    public function getModules(): ModuleManager
    {
        return $this->modules;
    }

    /**
     * Check if a module is enabled
     */
    public function hasModule(string $name): bool
    {
        return $this->modules->isEnabled($name);
    }

    /**
     * Require a module (throws exception if not enabled)
     *
     * @throws \ZephyrPHP\Module\ModuleNotEnabledException
     */
    public function requireModule(string $name): void
    {
        $this->modules->require($name);
    }

    /**
     * Resolve a service from the container
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    /**
     * Register a binding in the container
     */
    public function bind(string $abstract, mixed $concrete = null): void
    {
        $this->container->bind($abstract, $concrete);
    }

    /**
     * Register a singleton in the container
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    public function terminate(): void
    {
        if ($this->session !== null) {
            session_write_close();
        }

        $this->logger->debug('Application terminated');
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function name(): string
    {
        return $_ENV['APP_NAME'] ?? 'ZephyrPHP';
    }

    public function url(): string
    {
        // Auto-detect from request
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        return rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
    }

    public function basePath(string $path = ''): string
    {
        return BASE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}
