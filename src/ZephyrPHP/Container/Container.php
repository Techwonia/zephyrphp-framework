<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

use Psr\Container\ContainerInterface;
use DI\Container as DIContainer;
use DI\ContainerBuilder;
use Closure;
use ReflectionClass;
use ReflectionException;

/**
 * Zephyr Service Container
 *
 * A powerful, PSR-11 compatible dependency injection container designed for
 * the ZephyrPHP framework. Built with simplicity and performance in mind.
 *
 * ZephyrPHP uses a unique weather-themed API inspired by its name (Zephyr = gentle breeze).
 * This creates a distinctive and memorable developer experience.
 *
 * Zephyr API Methods (unique to ZephyrPHP):
 * - breeze()     → Transient binding (like a passing breeze)
 * - storm()      → Singleton binding (persistent like a storm system)
 * - gale()       → Factory binding (forceful, always fresh)
 * - mist()       → Scoped binding (lingers within a scope)
 * - vapor()      → Lazy binding (appears when needed)
 * - crystallize()→ Instance binding (frozen in place)
 * - wind()       → Alias (redirects like wind currents)
 * - current()    → Tag services (group related flows)
 * - cyclone()    → Decorator (wraps around services)
 * - summon()     → Resolve service (call forth)
 * - invoke()     → Method injection (channel the flow)
 * - forecast()   → Get binding definition (predict behavior)
 * - tempest()    → Validate bindings (test the storm)
 * - whirlpool()  → Create scope (temporary vortex)
 * - shelter()    → Service locator (protected subset)
 * - cascade()    → Bulk registration (flowing series)
 * - atmosphere() → Set parameter (environmental condition)
 * - climate()    → Environment binding (location-specific)
 * - preflight()  → Before resolving hook
 * - postflight() → After resolving hook
 * - windpipe()   → Resolution middleware
 *
 * Standard API methods are also available for compatibility with common patterns.
 *
 * @package ZephyrPHP
 * @author ZephyrPHP Team
 */
class Container implements ContainerInterface
{
    private static ?Container $instance = null;
    private ?DIContainer $container = null;

    // Binding stores
    private array $bindings = [];           // Transient bindings
    private array $singletons = [];         // Singleton bindings
    private array $factories = [];          // Factory bindings
    private array $scopedBindings = [];     // Scoped bindings
    private array $lazyBindings = [];       // Lazy bindings
    private array $instances = [];          // Resolved singleton instances
    private array $scopedInstances = [];    // Scoped instances

    // Service organization
    private array $aliases = [];            // Aliases
    private array $tags = [];               // Tags

    // Contextual bindings
    private array $contextualBindings = [];

    // Service providers
    private array $deferredProviders = [];  // Deferred providers
    private array $loadedProviders = [];    // Loaded providers

    // Compilation
    private bool $compiled = false;
    private ?string $compiledPath = null;

    // Resolution hooks
    private array $beforeResolvingCallbacks = [];
    private array $afterResolvingCallbacks = [];
    private array $globalBeforeResolvingCallbacks = [];
    private array $globalAfterResolvingCallbacks = [];

    // Build stack for cycle detection
    private array $buildStack = [];

    // Method bindings
    private array $methodBindings = [];

    // Rebind callbacks
    private array $rebindCallbacks = [];

    // Parameters (config values)
    private array $parameters = [];

    // Environment bindings
    private array $environmentBindings = [];
    private ?string $environment = null;

    // Decorators
    private array $decorators = [];

    // Resolution middleware
    private array $resolvingMiddleware = [];

    // Auto-injection rules
    private array $autoInjectRules = [];

    private function __construct() {}

    /**
     * Get the container instance (singleton)
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set/replace the container instance
     */
    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /**
     * Get the underlying PHP-DI container
     */
    public function getContainer(): DIContainer
    {
        if ($this->container === null) {
            $this->container = $this->buildContainer();
        }
        return $this->container;
    }

    /**
     * Set an external PHP-DI container
     */
    public function setContainer(DIContainer $container): void
    {
        $this->container = $container;
    }

    /**
     * Build the PHP-DI container with definitions
     */
    private function buildContainer(): DIContainer
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        if ($this->compiled && $this->compiledPath) {
            $builder->enableCompilation($this->compiledPath);
        }

        $definitions = [];

        // Add transient bindings
        foreach ($this->bindings as $abstract => $concrete) {
            $definitions[$abstract] = $concrete;
        }

        // Add singleton bindings
        foreach ($this->singletons as $abstract => $concrete) {
            $definitions[$abstract] = \DI\factory(function () use ($abstract, $concrete) {
                if (!isset($this->instances[$abstract])) {
                    $this->instances[$abstract] = $this->build($concrete);
                }
                return $this->instances[$abstract];
            });
        }

        // Add aliases
        foreach ($this->aliases as $alias => $abstract) {
            $definitions[$alias] = \DI\get($abstract);
        }

        if (!empty($definitions)) {
            $builder->addDefinitions($definitions);
        }

        // Load definitions from config
        $definitionsPath = defined('BASE_PATH') ? BASE_PATH . '/config/container.php' : null;
        if ($definitionsPath && file_exists($definitionsPath)) {
            $builder->addDefinitions($definitionsPath);
        }

        return $builder->build();
    }

    // ========================================================================
    // ZEPHYR WEATHER-THEMED API (Unique to ZephyrPHP)
    // ========================================================================

    /**
     * Register a transient binding (like a passing breeze - comes and goes)
     *
     * Zephyr API: Creates a new instance each time, fresh like a breeze.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $concrete The concrete implementation
     * @return self
     */
    public function breeze(string $abstract, mixed $concrete = null): self
    {
        return $this->bind($abstract, $concrete);
    }

    /**
     * Register a singleton binding (persistent like a storm system)
     *
     * Zephyr API: Same instance always returned, lasting like a storm.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $concrete The concrete implementation
     * @return self
     */
    public function storm(string $abstract, mixed $concrete = null): self
    {
        return $this->singleton($abstract, $concrete);
    }

    /**
     * Register a factory binding (forceful gale - always fresh and new)
     *
     * Zephyr API: Always creates a new instance, forceful like a gale.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $concrete The concrete implementation
     * @return self
     */
    public function gale(string $abstract, mixed $concrete = null): self
    {
        return $this->factory($abstract, $concrete);
    }

    /**
     * Register a scoped binding (mist that lingers within a scope)
     *
     * Zephyr API: Singleton per request/scope, like mist in an area.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $concrete The concrete implementation
     * @return self
     */
    public function mist(string $abstract, mixed $concrete = null): self
    {
        return $this->scoped($abstract, $concrete);
    }

    /**
     * Register a lazy binding (vapor - appears when condensed/needed)
     *
     * Zephyr API: Delayed instantiation, materializes when accessed.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $concrete The concrete implementation
     * @return self
     */
    public function vapor(string $abstract, mixed $concrete = null): self
    {
        return $this->lazy($abstract, $concrete);
    }

    /**
     * Register an existing instance (crystallize - frozen in place)
     *
     * Zephyr API: Binds an already-created object, crystallized.
     *
     * @param string $abstract The abstract type or interface
     * @param mixed $instance The existing instance
     * @return self
     */
    public function crystallize(string $abstract, mixed $instance): self
    {
        return $this->instance($abstract, $instance);
    }

    /**
     * Create an alias (wind - redirects the flow)
     *
     * Zephyr API: Creates an alternative name that redirects.
     *
     * @param string $alias The alias name
     * @param string $abstract The target abstract
     * @return self
     */
    public function wind(string $alias, string $abstract): self
    {
        return $this->alias($alias, $abstract);
    }

    /**
     * Tag services (current - group related flows together)
     *
     * Zephyr API: Groups services under a common tag.
     *
     * @param array|string $abstracts Services to tag
     * @param string $tag The tag name
     * @return self
     */
    public function current(array|string $abstracts, string $tag): self
    {
        return $this->tag($abstracts, $tag);
    }

    /**
     * Decorate a service (cyclone - wraps around the core)
     *
     * Zephyr API: Wraps a service with additional functionality.
     *
     * @param string $abstract The service to decorate
     * @param string|Closure $decorator The decorator
     * @param int $priority Higher priority wraps outer
     * @return self
     */
    public function cyclone(string $abstract, string|Closure $decorator, int $priority = 0): self
    {
        return $this->decorate($abstract, $decorator, $priority);
    }

    /**
     * Resolve a service (summon - call forth from the container)
     *
     * Zephyr API: Resolves and returns a service instance.
     *
     * @param string $abstract The service to resolve
     * @param array $parameters Optional parameters
     * @return mixed The resolved service
     */
    public function summon(string $abstract, array $parameters = []): mixed
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * Call a method with injection (invoke - channel the flow)
     *
     * Zephyr API: Calls a method with auto-injected dependencies.
     *
     * @param callable|array|string $callable The method to call
     * @param array $parameters Additional parameters
     * @return mixed The method result
     */
    public function invoke(callable|array|string $callable, array $parameters = []): mixed
    {
        return $this->call($callable, $parameters);
    }

    /**
     * Get binding definition (forecast - predict service behavior)
     *
     * Zephyr API: Returns detailed info about a binding.
     *
     * @param string $abstract The service to inspect
     * @return array Binding information
     */
    public function forecast(string $abstract): array
    {
        return $this->getDefinition($abstract);
    }

    /**
     * Validate all bindings (tempest - test the storm)
     *
     * Zephyr API: Validates all container bindings for issues.
     *
     * @return array Validation errors
     */
    public function tempest(): array
    {
        return $this->validate();
    }

    /**
     * Create a temporary scope (whirlpool - contained vortex)
     *
     * Zephyr API: Executes callback in isolated scope.
     *
     * @param Closure $callback The callback to execute
     * @return mixed The callback result
     */
    public function whirlpool(Closure $callback): mixed
    {
        return $this->scope($callback);
    }

    /**
     * Create a service locator (shelter - protected subset)
     *
     * Zephyr API: Creates a limited container with specific services.
     *
     * @param array $services Services to include
     * @return ServiceLocator The service locator
     */
    public function shelter(array $services): ServiceLocator
    {
        return $this->createServiceLocator($services);
    }

    /**
     * Bulk register bindings (cascade - flowing series of bindings)
     *
     * Zephyr API: Registers multiple bindings at once.
     * Unique feature: Supports mixed binding types in one call.
     *
     * @param array $bindings Array of bindings with optional type specification
     * @return self
     */
    public function cascade(array $bindings): self
    {
        foreach ($bindings as $abstract => $config) {
            if (is_array($config) && isset($config['concrete'])) {
                $concrete = $config['concrete'];
                $type = $config['type'] ?? 'breeze';

                match ($type) {
                    'storm', 'singleton' => $this->storm($abstract, $concrete),
                    'gale', 'factory' => $this->gale($abstract, $concrete),
                    'mist', 'scoped' => $this->mist($abstract, $concrete),
                    'vapor', 'lazy' => $this->vapor($abstract, $concrete),
                    default => $this->breeze($abstract, $concrete),
                };
            } else {
                $this->breeze($abstract, $config);
            }
        }

        return $this;
    }

    /**
     * Set a parameter (atmosphere - environmental condition)
     *
     * Zephyr API: Sets a configuration parameter.
     *
     * @param string $key Parameter name
     * @param mixed $value Parameter value
     * @return self
     */
    public function atmosphere(string $key, mixed $value): self
    {
        return $this->setParameter($key, $value);
    }

    /**
     * Environment-specific binding (climate - location-specific weather)
     *
     * Zephyr API: Register bindings for specific environments.
     *
     * @param string $environment The environment name
     * @return EnvironmentBindingBuilder
     */
    public function climate(string $environment): EnvironmentBindingBuilder
    {
        return $this->whenEnvironment($environment);
    }

    /**
     * Before resolving hook (preflight - preparation before takeoff)
     *
     * Zephyr API: Register callback before service resolution.
     *
     * @param string|Closure $abstract Service or global callback
     * @param Closure|null $callback The callback
     * @return self
     */
    public function preflight(string|Closure $abstract, ?Closure $callback = null): self
    {
        return $this->beforeResolving($abstract, $callback);
    }

    /**
     * After resolving hook (postflight - actions after landing)
     *
     * Zephyr API: Register callback after service resolution.
     *
     * @param string|Closure $abstract Service or global callback
     * @param Closure|null $callback The callback
     * @return self
     */
    public function postflight(string|Closure $abstract, ?Closure $callback = null): self
    {
        return $this->afterResolving($abstract, $callback);
    }

    /**
     * Add resolution middleware (windpipe - flow modifier)
     *
     * Zephyr API: Add middleware to the resolution pipeline.
     *
     * @param Closure $middleware The middleware
     * @return self
     */
    public function windpipe(Closure $middleware): self
    {
        return $this->addResolutionMiddleware($middleware);
    }

    /**
     * Conditional breeze binding (breezeIf - bind only if condition met)
     *
     * Zephyr API: Binds only if not already bound OR condition is true.
     * Unique feature: Supports closure condition for runtime checks.
     *
     * @param string $abstract The abstract type
     * @param mixed $concrete The concrete implementation
     * @param Closure|bool|null $condition Optional condition (default: not bound check)
     * @return self
     */
    public function breezeIf(string $abstract, mixed $concrete = null, Closure|bool|null $condition = null): self
    {
        $shouldBind = match (true) {
            $condition instanceof Closure => $condition($this),
            is_bool($condition) => $condition,
            default => !$this->bound($abstract),
        };

        if ($shouldBind) {
            $this->breeze($abstract, $concrete);
        }

        return $this;
    }

    /**
     * Conditional storm binding (stormIf - singleton only if condition met)
     *
     * Zephyr API: Creates singleton only if not already bound.
     *
     * @param string $abstract The abstract type
     * @param mixed $concrete The concrete implementation
     * @param Closure|bool|null $condition Optional condition
     * @return self
     */
    public function stormIf(string $abstract, mixed $concrete = null, Closure|bool|null $condition = null): self
    {
        $shouldBind = match (true) {
            $condition instanceof Closure => $condition($this),
            is_bool($condition) => $condition,
            default => !$this->bound($abstract),
        };

        if ($shouldBind) {
            $this->storm($abstract, $concrete);
        }

        return $this;
    }

    /**
     * Resolve placeholder values (breathe - bring life to placeholders)
     *
     * Zephyr API: Resolves %param%, %env(VAR)%, %env(type:VAR)% placeholders.
     * Unique feature: Supports base64 decoding with %env(base64:VAR)%.
     *
     * @param string $value The string with placeholders
     * @return mixed The resolved value
     */
    public function breathe(string $value): mixed
    {
        return $this->resolveParameterPlaceholders($value);
    }

    /**
     * Auto-bind by convention (autoInject - smart binding rules)
     *
     * Zephyr API: Automatically bind interfaces to implementations by namespace.
     *
     * @param string $interfaceNs Interface namespace
     * @param string $implementationNs Implementation namespace
     * @return self
     */
    public function autoInject(string $interfaceNs, string $implementationNs): self
    {
        return $this->autoWire($interfaceNs, $implementationNs);
    }

    /**
     * Get currents (tagged services resolved)
     *
     * Zephyr API: Resolves all services with a given tag.
     *
     * @param string $tag The tag name
     * @return array Resolved services
     */
    public function currents(string $tag): array
    {
        return $this->tagged($tag);
    }

    /**
     * Disperse - clear all scoped instances (end of mist)
     *
     * Zephyr API: Clears all scoped instances.
     *
     * @return void
     */
    public function disperse(): void
    {
        $this->clearScopedInstances();
    }

    /**
     * Dissipate - forget a specific instance
     *
     * Zephyr API: Removes a resolved instance from cache.
     *
     * @param string $abstract The service to forget
     * @return void
     */
    public function dissipate(string $abstract): void
    {
        $this->forgetInstance($abstract);
    }

    /**
     * Clear skies - flush all container state
     *
     * Zephyr API: Completely resets the container.
     *
     * @return void
     */
    public function clearSkies(): void
    {
        $this->flush();
    }

    // ========================================================================
    // STANDARD BINDING METHODS (Compatibility Layer)
    // ========================================================================

    /**
     * Register a binding (transient - new instance possible each time)
     */
    public function bind(string $abstract, mixed $concrete = null): self
    {
        $this->dropStaleInstances($abstract);
        $this->bindings[$abstract] = $concrete ?? $abstract;
        $this->container = null;
        $this->fireRebindCallbacks($abstract);

        return $this;
    }

    /**
     * Register a singleton (same instance always)
     */
    public function singleton(string $abstract, mixed $concrete = null): self
    {
        $this->dropStaleInstances($abstract);
        $this->singletons[$abstract] = $concrete ?? $abstract;
        $this->container = null;
        $this->fireRebindCallbacks($abstract);

        return $this;
    }

    /**
     * Register a factory (always creates new instance)
     */
    public function factory(string $abstract, mixed $concrete = null): self
    {
        $this->dropStaleInstances($abstract);
        $this->factories[$abstract] = $concrete ?? $abstract;
        $this->container = null;

        return $this;
    }

    /**
     * Register a scoped binding (singleton per request/scope)
     */
    public function scoped(string $abstract, mixed $concrete = null): self
    {
        $this->dropStaleInstances($abstract);
        $this->scopedBindings[$abstract] = $concrete ?? $abstract;
        $this->container = null;

        return $this;
    }

    /**
     * Register a lazy binding (delayed instantiation)
     */
    public function lazy(string $abstract, mixed $concrete = null): self
    {
        $this->lazyBindings[$abstract] = $concrete ?? $abstract;
        $this->singleton($abstract, function () use ($abstract) {
            return $this->createLazyProxy($abstract);
        });

        return $this;
    }

    /**
     * Register an existing instance
     */
    public function instance(string $abstract, mixed $instance): self
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = fn() => $instance;
        $this->container = null;
        $this->fireRebindCallbacks($abstract);

        return $this;
    }

    /**
     * Register a binding only if not already registered
     */
    public function bindIf(string $abstract, mixed $concrete = null): self
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete);
        }
        return $this;
    }

    /**
     * Register a singleton only if not already registered
     */
    public function singletonIf(string $abstract, mixed $concrete = null): self
    {
        if (!$this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
        return $this;
    }

    // ========================================================================
    // LAZY PROXY
    // ========================================================================

    /**
     * Create a lazy proxy for delayed instantiation
     */
    protected function createLazyProxy(string $abstract): object
    {
        $concrete = $this->lazyBindings[$abstract] ?? $abstract;

        return new class($this, $abstract, $concrete) {
            private ?object $instance = null;
            private Container $container;
            private string $abstract;
            private mixed $concrete;

            public function __construct(Container $container, string $abstract, mixed $concrete)
            {
                $this->container = $container;
                $this->abstract = $abstract;
                $this->concrete = $concrete;
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->getInstance()->$method(...$arguments);
            }

            public function __get(string $name): mixed
            {
                return $this->getInstance()->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                $this->getInstance()->$name = $value;
            }

            public function __isset(string $name): bool
            {
                return isset($this->getInstance()->$name);
            }

            private function getInstance(): object
            {
                if ($this->instance === null) {
                    $this->instance = $this->container->build($this->concrete);
                }
                return $this->instance;
            }
        };
    }

    // ========================================================================
    // ALIASES
    // ========================================================================

    /**
     * Create an alias for a service
     */
    public function alias(string $alias, string $abstract): self
    {
        if ($alias === $abstract) {
            throw new \InvalidArgumentException("Alias [{$alias}] cannot reference itself.");
        }

        $this->aliases[$alias] = $abstract;
        $this->container = null;

        return $this;
    }

    /**
     * Get the real abstract from an alias
     */
    public function getAlias(string $abstract): string
    {
        if (!isset($this->aliases[$abstract])) {
            return $abstract;
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Check if a name is an alias
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    // ========================================================================
    // TAGGING
    // ========================================================================

    /**
     * Tag services
     */
    public function tag(array|string $abstracts, string $tag): self
    {
        $abstracts = is_array($abstracts) ? $abstracts : [$abstracts];

        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }

        foreach ($abstracts as $abstract) {
            if (!in_array($abstract, $this->tags[$tag])) {
                $this->tags[$tag][] = $abstract;
            }
        }

        return $this;
    }

    /**
     * Get all services with a tag (resolved)
     */
    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        $services = [];
        foreach ($this->tags[$tag] as $abstract) {
            $services[] = $this->make($abstract);
        }

        return $services;
    }

    /**
     * Get all abstracts with a tag without resolving
     */
    public function getTagged(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    // ========================================================================
    // CONTEXTUAL BINDINGS
    // ========================================================================

    /**
     * Define a contextual binding
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Add a contextual binding
     */
    public function addContextualBinding(string $concrete, string $abstract, mixed $implementation): void
    {
        $this->contextualBindings[$concrete][$abstract] = $implementation;
    }

    /**
     * Get a contextual binding
     */
    public function getContextualBinding(string $concrete, string $abstract): mixed
    {
        return $this->contextualBindings[$concrete][$abstract] ?? null;
    }

    // ========================================================================
    // PARAMETERS
    // ========================================================================

    /**
     * Set a parameter
     */
    public function setParameter(string $key, mixed $value): self
    {
        $this->parameters[$key] = $value;
        return $this;
    }

    /**
     * Get a parameter
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Check if parameter exists
     */
    public function hasParameter(string $key): bool
    {
        return isset($this->parameters[$key]);
    }

    /**
     * Set multiple parameters
     */
    public function setParameters(array $parameters): self
    {
        foreach ($parameters as $key => $value) {
            $this->parameters[$key] = $value;
        }
        return $this;
    }

    /**
     * Get all parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Resolve placeholders in a string
     * Supports: %param% and %env(VAR)% and %env(type:VAR)%
     */
    public function resolveParameterPlaceholders(string $value): mixed
    {
        // Resolve %env(VAR)% placeholders
        $value = preg_replace_callback('/%env\(([^)]+)\)%/', function ($matches) {
            $envVar = $matches[1];

            if (str_contains($envVar, ':')) {
                [$type, $envVar] = explode(':', $envVar, 2);
                $envValue = $_ENV[$envVar] ?? getenv($envVar) ?: null;

                return match ($type) {
                    'int' => (int) $envValue,
                    'float' => (float) $envValue,
                    'bool' => filter_var($envValue, FILTER_VALIDATE_BOOLEAN),
                    'string' => (string) $envValue,
                    'json' => json_decode($envValue, true),
                    'base64' => base64_decode($envValue),
                    default => $envValue,
                };
            }

            return $_ENV[$envVar] ?? getenv($envVar) ?: null;
        }, $value);

        // Resolve %parameter% placeholders
        $value = preg_replace_callback('/%([^%]+)%/', function ($matches) {
            return $this->getParameter($matches[1], $matches[0]);
        }, $value);

        return $value;
    }

    // ========================================================================
    // ENVIRONMENT-AWARE BINDINGS
    // ========================================================================

    /**
     * Set the current environment
     */
    public function setEnvironment(string $environment): self
    {
        $this->environment = $environment;
        $this->applyEnvironmentBindings();
        return $this;
    }

    /**
     * Get the current environment
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Define environment-specific bindings
     */
    public function whenEnvironment(string $environment): EnvironmentBindingBuilder
    {
        return new EnvironmentBindingBuilder($this, $environment);
    }

    /**
     * Add an environment binding
     */
    public function addEnvironmentBinding(string $environment, string $abstract, mixed $concrete, string $type = 'bind'): void
    {
        $this->environmentBindings[$environment][$abstract] = [
            'concrete' => $concrete,
            'type' => $type,
        ];

        if ($this->environment === $environment) {
            $this->applyBindingByType($abstract, $concrete, $type);
        }
    }

    /**
     * Apply all environment bindings
     */
    protected function applyEnvironmentBindings(): void
    {
        if (!$this->environment || !isset($this->environmentBindings[$this->environment])) {
            return;
        }

        foreach ($this->environmentBindings[$this->environment] as $abstract => $binding) {
            $this->applyBindingByType($abstract, $binding['concrete'], $binding['type']);
        }
    }

    /**
     * Apply a binding by type
     */
    protected function applyBindingByType(string $abstract, mixed $concrete, string $type): void
    {
        match ($type) {
            'singleton' => $this->singleton($abstract, $concrete),
            'factory' => $this->factory($abstract, $concrete),
            'scoped' => $this->scoped($abstract, $concrete),
            default => $this->bind($abstract, $concrete),
        };
    }

    // ========================================================================
    // DECORATORS
    // ========================================================================

    /**
     * Decorate a service
     */
    public function decorate(string $abstract, string|Closure $decorator, int $priority = 0): self
    {
        if (!isset($this->decorators[$abstract])) {
            $this->decorators[$abstract] = [];
        }

        $this->decorators[$abstract][] = [
            'decorator' => $decorator,
            'priority' => $priority,
        ];

        // Sort by priority (higher wraps lower)
        usort($this->decorators[$abstract], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Get the decorator stack for a service
     */
    public function getDecoratorStack(string $abstract): array
    {
        return $this->decorators[$abstract] ?? [];
    }

    /**
     * Apply decorators to an instance
     */
    protected function applyDecorators(string $abstract, mixed $instance): mixed
    {
        if (!isset($this->decorators[$abstract])) {
            return $instance;
        }

        foreach ($this->decorators[$abstract] as $decoratorInfo) {
            $decorator = $decoratorInfo['decorator'];

            if ($decorator instanceof Closure) {
                $instance = $decorator($instance, $this);
            } elseif (is_string($decorator) && class_exists($decorator)) {
                $instance = new $decorator($instance);
            }
        }

        return $instance;
    }

    // ========================================================================
    // RESOLUTION MIDDLEWARE
    // ========================================================================

    /**
     * Add resolution middleware
     */
    public function addResolutionMiddleware(Closure $middleware): self
    {
        $this->resolvingMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Run resolution through middleware
     */
    protected function throughMiddleware(string $abstract, Closure $resolver): mixed
    {
        if (empty($this->resolvingMiddleware)) {
            return $resolver();
        }

        $pipeline = array_reduce(
            array_reverse($this->resolvingMiddleware),
            fn($next, $middleware) => fn() => $middleware($abstract, $next, $this),
            $resolver
        );

        return $pipeline();
    }

    // ========================================================================
    // SERVICE LOCATOR
    // ========================================================================

    /**
     * Create a service locator with specific services
     */
    public function createServiceLocator(array $services): ServiceLocator
    {
        return new ServiceLocator($this, $services);
    }

    // ========================================================================
    // RESOLVING HOOKS
    // ========================================================================

    /**
     * Register a callback to run before resolving
     */
    public function beforeResolving(string|Closure $abstract, ?Closure $callback = null): self
    {
        if ($abstract instanceof Closure) {
            $this->globalBeforeResolvingCallbacks[] = $abstract;
            return $this;
        }

        $abstract = $this->getAlias($abstract);
        $this->beforeResolvingCallbacks[$abstract][] = $callback;

        return $this;
    }

    /**
     * Register a callback to run after resolving
     */
    public function afterResolving(string|Closure $abstract, ?Closure $callback = null): self
    {
        if ($abstract instanceof Closure) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
            return $this;
        }

        $abstract = $this->getAlias($abstract);
        $this->afterResolvingCallbacks[$abstract][] = $callback;

        return $this;
    }

    /**
     * Alias for afterResolving
     */
    public function resolving(string|Closure $abstract, ?Closure $callback = null): self
    {
        return $this->afterResolving($abstract, $callback);
    }

    /**
     * Fire before resolving callbacks
     */
    protected function fireBeforeResolvingCallbacks(string $abstract, array $parameters = []): void
    {
        foreach ($this->globalBeforeResolvingCallbacks as $callback) {
            $callback($abstract, $parameters, $this);
        }

        foreach ($this->beforeResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($abstract, $parameters, $this);
        }
    }

    /**
     * Fire after resolving callbacks
     */
    protected function fireAfterResolvingCallbacks(string $abstract, mixed $object): void
    {
        foreach ($this->globalAfterResolvingCallbacks as $callback) {
            $callback($object, $this);
        }

        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($object, $this);
        }
    }

    // ========================================================================
    // REBINDING
    // ========================================================================

    /**
     * Register a rebind callback
     */
    public function rebinding(string $abstract, Closure $callback): mixed
    {
        $abstract = $this->getAlias($abstract);
        $this->rebindCallbacks[$abstract][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }

        return null;
    }

    /**
     * Fire rebind callbacks
     */
    protected function fireRebindCallbacks(string $abstract): void
    {
        $abstract = $this->getAlias($abstract);

        if (!isset($this->rebindCallbacks[$abstract])) {
            return;
        }

        foreach ($this->rebindCallbacks[$abstract] as $callback) {
            $callback($this->make($abstract), $this);
        }
    }

    // ========================================================================
    // METHOD BINDINGS
    // ========================================================================

    /**
     * Bind a method
     */
    public function bindMethod(string $method, Closure $callback): self
    {
        $this->methodBindings[$method] = $callback;
        return $this;
    }

    /**
     * Check if method binding exists
     */
    public function hasMethodBinding(string $method): bool
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Call a method binding
     */
    public function callMethodBinding(string $method, mixed $instance): mixed
    {
        if (!isset($this->methodBindings[$method])) {
            throw new BindingResolutionException("No method binding exists for [{$method}].");
        }

        return ($this->methodBindings[$method])($instance, $this);
    }

    // ========================================================================
    // RESOLUTION METHODS
    // ========================================================================

    /**
     * Resolve a service from the container (PSR-11)
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Check if a service exists (PSR-11)
     */
    public function has(string $id): bool
    {
        $id = $this->getAlias($id);

        if (isset($this->instances[$id])) {
            return true;
        }

        if (isset($this->bindings[$id]) || isset($this->singletons[$id])) {
            return true;
        }

        if (isset($this->factories[$id]) || isset($this->scopedBindings[$id])) {
            return true;
        }

        if (isset($this->deferredProviders[$id])) {
            return true;
        }

        return $this->getContainer()->has($id);
    }

    /**
     * Resolve a service from the container
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        return $this->throughMiddleware($abstract, function () use ($abstract, $parameters) {
            $this->fireBeforeResolvingCallbacks($abstract, $parameters);

            // Load deferred provider if needed
            if (isset($this->deferredProviders[$abstract])) {
                $this->loadDeferredProvider($abstract);
            }

            // Factory bindings - always new
            if (isset($this->factories[$abstract])) {
                $object = $this->build($this->factories[$abstract], $parameters);
                $object = $this->applyDecorators($abstract, $object);
                $this->fireAfterResolvingCallbacks($abstract, $object);
                return $object;
            }

            // Scoped bindings - singleton per scope
            if (isset($this->scopedBindings[$abstract])) {
                if (!isset($this->scopedInstances[$abstract])) {
                    $object = $this->build($this->scopedBindings[$abstract], $parameters);
                    $this->scopedInstances[$abstract] = $this->applyDecorators($abstract, $object);
                }
                $object = $this->scopedInstances[$abstract];
                $this->fireAfterResolvingCallbacks($abstract, $object);
                return $object;
            }

            // Check resolved instances (singletons)
            if (isset($this->instances[$abstract]) && empty($parameters)) {
                $this->fireAfterResolvingCallbacks($abstract, $this->instances[$abstract]);
                return $this->instances[$abstract];
            }

            // Build it
            $object = $this->resolve($abstract, $parameters);
            $object = $this->applyDecorators($abstract, $object);

            $this->fireAfterResolvingCallbacks($abstract, $object);

            return $object;
        });
    }

    /**
     * Resolve a service (internal)
     */
    protected function resolve(string $abstract, array $parameters = []): mixed
    {
        if (in_array($abstract, $this->buildStack)) {
            throw BindingResolutionException::circularDependency($abstract, $this->buildStack);
        }

        $this->buildStack[] = $abstract;

        try {
            if (!empty($parameters)) {
                return $this->getContainer()->make($abstract, $parameters);
            }

            return $this->getContainer()->get($abstract);
        } catch (\DI\NotFoundException $e) {
            throw NotFoundException::notFound($abstract);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * Build a concrete instance
     */
    public function build(mixed $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (is_string($concrete) && class_exists($concrete)) {
            if (!empty($parameters)) {
                return $this->getContainer()->make($concrete, $parameters);
            }
            return $this->getContainer()->get($concrete);
        }

        return $concrete;
    }

    /**
     * Call a method with dependency injection
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable, 2);

            if ($this->hasMethodBinding($callable)) {
                $instance = $this->make($class);
                return $this->callMethodBinding($callable, $instance);
            }

            $callable = [$this->make($class), $method];
        }

        return $this->getContainer()->call($callable, $parameters);
    }

    /**
     * Make with context
     */
    public function makeWith(string $abstract, string $context, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);
        $contextualConcrete = $this->getContextualBinding($context, $abstract);

        if ($contextualConcrete !== null) {
            if ($contextualConcrete instanceof Closure) {
                return $contextualConcrete($this, $parameters);
            }
            return $this->make($contextualConcrete, $parameters);
        }

        return $this->make($abstract, $parameters);
    }

    // ========================================================================
    // BULK REGISTRATION
    // ========================================================================

    /**
     * Register multiple bindings at once
     */
    public function registerMany(array $bindings, string $type = 'singleton'): self
    {
        foreach ($bindings as $abstract => $concrete) {
            match ($type) {
                'singleton' => $this->singleton($abstract, $concrete),
                'factory' => $this->factory($abstract, $concrete),
                'scoped' => $this->scoped($abstract, $concrete),
                'lazy' => $this->lazy($abstract, $concrete),
                default => $this->bind($abstract, $concrete),
            };
        }

        return $this;
    }

    /**
     * Auto-bind interfaces to implementations by convention
     */
    public function autoWire(string $interfaceNamespace, string $implementationNamespace): self
    {
        $this->autoInjectRules[] = [
            'interface' => rtrim($interfaceNamespace, '\\') . '\\',
            'implementation' => rtrim($implementationNamespace, '\\') . '\\',
        ];

        return $this;
    }

    /**
     * Create a temporary scope
     */
    public function scope(Closure $callback): mixed
    {
        $previousScopedInstances = $this->scopedInstances;
        $this->scopedInstances = [];

        try {
            return $callback($this);
        } finally {
            $this->scopedInstances = $previousScopedInstances;
        }
    }

    /**
     * Get binding definition/info
     */
    public function getDefinition(string $abstract): array
    {
        $abstract = $this->getAlias($abstract);

        return [
            'abstract' => $abstract,
            'type' => $this->getBindingType($abstract),
            'concrete' => $this->bindings[$abstract]
                ?? $this->singletons[$abstract]
                ?? $this->factories[$abstract]
                ?? $this->scopedBindings[$abstract]
                ?? $this->lazyBindings[$abstract]
                ?? null,
            'resolved' => isset($this->instances[$abstract]),
            'aliases' => array_keys(array_filter($this->aliases, fn($v) => $v === $abstract)),
            'tags' => $this->getTagsForAbstract($abstract),
            'decorators' => $this->decorators[$abstract] ?? [],
        ];
    }

    // ========================================================================
    // VALIDATION & DEBUGGING
    // ========================================================================

    /**
     * Validate all bindings
     */
    public function validate(): array
    {
        $errors = [];

        $allBindings = array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->factories),
            array_keys($this->scopedBindings)
        );

        foreach ($allBindings as $abstract) {
            try {
                $this->validateBinding($abstract);
            } catch (\Throwable $e) {
                $errors[$abstract] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Validate a single binding
     */
    protected function validateBinding(string $abstract): void
    {
        $concrete = $this->bindings[$abstract]
            ?? $this->singletons[$abstract]
            ?? $this->factories[$abstract]
            ?? $this->scopedBindings[$abstract]
            ?? null;

        if ($concrete === null) {
            return;
        }

        if ($concrete instanceof Closure) {
            return;
        }

        if (is_string($concrete) && !class_exists($concrete) && !interface_exists($concrete)) {
            throw BindingResolutionException::unresolvable($abstract, "Class [{$concrete}] does not exist");
        }

        if (is_string($concrete) && class_exists($concrete)) {
            try {
                $reflection = new ReflectionClass($concrete);
                if (!$reflection->isInstantiable()) {
                    throw BindingResolutionException::unresolvable(
                        $abstract,
                        "Class [{$concrete}] is not instantiable"
                    );
                }
            } catch (ReflectionException $e) {
                throw BindingResolutionException::unresolvable($abstract, $e->getMessage());
            }
        }
    }

    /**
     * Lint the container
     */
    public function lint(array $options = []): array
    {
        $issues = [];

        $options = array_merge([
            'check_unused' => false,
            'check_types' => true,
            'check_cycles' => true,
        ], $options);

        $issues['validation'] = $this->validate();

        if ($options['check_cycles']) {
            $issues['cycles'] = $this->detectCycles();
        }

        return $issues;
    }

    /**
     * Detect circular dependencies
     */
    protected function detectCycles(): array
    {
        $cycles = [];

        foreach (array_keys($this->singletons) as $abstract) {
            try {
                $this->checkCycle($abstract, []);
            } catch (BindingResolutionException $e) {
                $cycles[] = $e->getMessage();
            }
        }

        return $cycles;
    }

    /**
     * Check for cycles recursively
     */
    protected function checkCycle(string $abstract, array $visited): void
    {
        if (in_array($abstract, $visited)) {
            throw BindingResolutionException::circularDependency($abstract, $visited);
        }

        $visited[] = $abstract;
        $concrete = $this->singletons[$abstract] ?? null;

        if (is_string($concrete) && class_exists($concrete)) {
            try {
                $reflection = new ReflectionClass($concrete);
                $constructor = $reflection->getConstructor();

                if ($constructor) {
                    foreach ($constructor->getParameters() as $param) {
                        $type = $param->getType();
                        if ($type && !$type->isBuiltin()) {
                            $typeName = $type->getName();
                            if (isset($this->singletons[$typeName])) {
                                $this->checkCycle($typeName, $visited);
                            }
                        }
                    }
                }
            } catch (ReflectionException $e) {
                // Skip
            }
        }
    }

    /**
     * Get all service IDs
     */
    public function getServiceIds(): array
    {
        return array_unique(array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->factories),
            array_keys($this->scopedBindings),
            array_keys($this->aliases)
        ));
    }

    /**
     * Get binding type for an abstract
     */
    protected function getBindingType(string $abstract): string
    {
        if (isset($this->singletons[$abstract])) {
            return 'singleton';
        }
        if (isset($this->factories[$abstract])) {
            return 'factory';
        }
        if (isset($this->scopedBindings[$abstract])) {
            return 'scoped';
        }
        if (isset($this->lazyBindings[$abstract])) {
            return 'lazy';
        }
        if (isset($this->bindings[$abstract])) {
            return 'bind';
        }
        return 'unknown';
    }

    /**
     * Get tags for an abstract
     */
    protected function getTagsForAbstract(string $abstract): array
    {
        $result = [];
        foreach ($this->tags as $tag => $abstracts) {
            if (in_array($abstract, $abstracts)) {
                $result[] = $tag;
            }
        }
        return $result;
    }

    /**
     * Dump container state
     */
    public function dump(): array
    {
        return [
            'bindings' => array_keys($this->bindings),
            'singletons' => array_keys($this->singletons),
            'factories' => array_keys($this->factories),
            'scoped' => array_keys($this->scopedBindings),
            'lazy' => array_keys($this->lazyBindings),
            'instances' => array_keys($this->instances),
            'aliases' => $this->aliases,
            'tags' => $this->tags,
            'providers' => array_keys($this->loadedProviders),
            'deferred' => array_keys($this->deferredProviders),
            'parameters' => array_keys($this->parameters),
            'environment' => $this->environment,
        ];
    }

    // ========================================================================
    // SCOPED INSTANCES
    // ========================================================================

    /**
     * Clear all scoped instances
     */
    public function clearScopedInstances(): void
    {
        $this->scopedInstances = [];
    }

    /**
     * Get all scoped bindings
     */
    public function getScopedBindings(): array
    {
        return $this->scopedBindings;
    }

    // ========================================================================
    // SERVICE PROVIDERS
    // ========================================================================

    /**
     * Register a deferred provider
     */
    public function registerDeferredProvider(string $provider, array $services): void
    {
        foreach ($services as $service) {
            $this->deferredProviders[$service] = $provider;
        }
    }

    /**
     * Load a deferred provider
     */
    private function loadDeferredProvider(string $service): void
    {
        if (!isset($this->deferredProviders[$service])) {
            return;
        }

        $provider = $this->deferredProviders[$service];

        foreach ($this->deferredProviders as $s => $p) {
            if ($p === $provider) {
                unset($this->deferredProviders[$s]);
            }
        }

        if (!isset($this->loadedProviders[$provider])) {
            $this->registerProvider($provider);
        }
    }

    /**
     * Register a provider by class name
     */
    public function registerProvider(string $providerClass): void
    {
        if (isset($this->loadedProviders[$providerClass])) {
            return;
        }

        if (!class_exists($providerClass)) {
            throw NotFoundException::providerNotFound($providerClass);
        }

        $provider = new $providerClass();

        if ($provider instanceof ServiceProvider) {
            $provider->register($this);
            $this->loadedProviders[$providerClass] = $provider;
        }
    }

    /**
     * Register a provider instance
     */
    public function register(ServiceProvider $provider): void
    {
        $providerClass = get_class($provider);

        if (isset($this->loadedProviders[$providerClass])) {
            return;
        }

        if ($provider instanceof DeferredServiceProvider) {
            $this->registerDeferredProvider($providerClass, $provider->provides());
            return;
        }

        $provider->register($this);
        $this->loadedProviders[$providerClass] = $provider;
    }

    /**
     * Boot all providers
     */
    public function boot(array $providers = []): void
    {
        foreach ($providers as $provider) {
            if (is_string($provider)) {
                $this->registerProvider($provider);
            } elseif ($provider instanceof ServiceProvider) {
                $this->register($provider);
            }
        }

        foreach ($this->loadedProviders as $provider) {
            if ($provider instanceof ServiceProvider) {
                $provider->boot($this);
            }
        }
    }

    // ========================================================================
    // COMPILATION
    // ========================================================================

    /**
     * Enable compilation
     */
    public function enableCompilation(string $path): self
    {
        $this->compiled = true;
        $this->compiledPath = $path;
        $this->container = null;
        return $this;
    }

    /**
     * Disable compilation
     */
    public function disableCompilation(): self
    {
        $this->compiled = false;
        $this->compiledPath = null;
        $this->container = null;
        return $this;
    }

    /**
     * Check if compiled
     */
    public function isCompiled(): bool
    {
        return $this->compiled;
    }

    // ========================================================================
    // EXTENSION
    // ========================================================================

    /**
     * Extend an existing binding
     */
    public function extend(string $abstract, Closure $closure): self
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            $this->fireRebindCallbacks($abstract);
            return $this;
        }

        $originalBinding = $this->bindings[$abstract] ?? $this->singletons[$abstract] ?? null;

        $this->bind($abstract, function ($container) use ($originalBinding, $closure) {
            $original = $originalBinding instanceof Closure
                ? $originalBinding($container)
                : (is_string($originalBinding) ? $container->make($originalBinding) : $originalBinding);

            return $closure($original, $container);
        });

        return $this;
    }

    // ========================================================================
    // UTILITY
    // ========================================================================

    /**
     * Flush all bindings
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->singletons = [];
        $this->factories = [];
        $this->scopedBindings = [];
        $this->lazyBindings = [];
        $this->instances = [];
        $this->scopedInstances = [];
        $this->aliases = [];
        $this->tags = [];
        $this->contextualBindings = [];
        $this->beforeResolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->globalBeforeResolvingCallbacks = [];
        $this->globalAfterResolvingCallbacks = [];
        $this->methodBindings = [];
        $this->rebindCallbacks = [];
        $this->parameters = [];
        $this->environmentBindings = [];
        $this->decorators = [];
        $this->resolvingMiddleware = [];
        $this->autoInjectRules = [];
        $this->container = null;
    }

    /**
     * Drop stale instances
     */
    protected function dropStaleInstances(string $abstract): void
    {
        unset($this->instances[$abstract]);
        unset($this->scopedInstances[$abstract]);
    }

    /**
     * Get all bindings
     */
    public function getBindings(): array
    {
        return array_merge(
            $this->bindings,
            $this->singletons,
            $this->factories,
            $this->scopedBindings
        );
    }

    /**
     * Check if bound
     */
    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->factories[$abstract])
            || isset($this->scopedBindings[$abstract])
            || isset($this->instances[$abstract]);
    }

    /**
     * Check if resolved
     */
    public function resolved(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);
        return isset($this->instances[$abstract]) || isset($this->scopedInstances[$abstract]);
    }

    /**
     * Forget an instance
     */
    public function forgetInstance(string $abstract): void
    {
        $abstract = $this->getAlias($abstract);
        unset($this->instances[$abstract]);
        unset($this->scopedInstances[$abstract]);
    }

    /**
     * Forget all instances
     */
    public function forgetInstances(): void
    {
        $this->instances = [];
        $this->scopedInstances = [];
    }

    /**
     * Get loaded providers
     */
    public function getLoadedProviders(): array
    {
        return array_keys($this->loadedProviders);
    }

    /**
     * Check if provider is loaded
     */
    public function providerIsLoaded(string $provider): bool
    {
        return isset($this->loadedProviders[$provider]);
    }

    /**
     * Get build stack
     */
    public function getBuildStack(): array
    {
        return $this->buildStack;
    }
}
