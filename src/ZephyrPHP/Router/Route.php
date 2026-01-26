<?php

declare(strict_types=1);

namespace ZephyrPHP\Router;

use ZephyrPHP\Container\Container as AppContainer;
use ZephyrPHP\Core\Http\Response;
use ZephyrPHP\Exception\HttpException;
use Closure;

/**
 * Zephyr Router
 *
 * A powerful, user-friendly routing system with industry-standard features.
 *
 * Features:
 * - HTTP verb methods (get, post, put, patch, delete, options, any)
 * - Route parameters with constraints
 * - Named routes for URL generation
 * - Route groups with prefix and middleware
 * - Resource and API resource routes
 * - Route model binding
 * - Fluent constraint helpers
 * - Controller string syntax ('Controller@method')
 * - Middleware support
 * - Fallback routes
 * - Domain/subdomain routing
 *
 * @package ZephyrPHP
 * @author ZephyrPHP Team
 */
class Route
{
    private static array $routes = [];
    private static array $namedRoutes = [];
    private static array $middlewares = [];
    private static array $groupMiddleware = [];
    private static string $prefix = '';
    private static array $routeCache = [];
    private static ?array $fallbackRoute = null;
    private static ?string $currentDomain = null;
    private static array $modelBindings = [];
    private static array $patterns = [];

    // ========================================================================
    // ROUTE DEFINITION METHODS
    // ========================================================================

    /**
     * Register a GET route
     */
    public static function get(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['GET', 'HEAD'], $path, $callback, $middlewares);
    }

    /**
     * Register a POST route
     */
    public static function post(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['POST'], $path, $callback, $middlewares);
    }

    /**
     * Register a PUT route
     */
    public static function put(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['PUT'], $path, $callback, $middlewares);
    }

    /**
     * Register a PATCH route
     */
    public static function patch(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['PATCH'], $path, $callback, $middlewares);
    }

    /**
     * Register a DELETE route
     */
    public static function delete(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['DELETE'], $path, $callback, $middlewares);
    }

    /**
     * Register an OPTIONS route
     */
    public static function options(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['OPTIONS'], $path, $callback, $middlewares);
    }

    /**
     * Register a route for all HTTP methods
     */
    public static function any(string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $callback, $middlewares);
    }

    /**
     * Register a route for specific HTTP methods
     */
    public static function match(array $methods, string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        return self::addRoute($methods, $path, $callback, $middlewares);
    }

    /**
     * Register a redirect route
     */
    public static function redirect(string $from, string $to, int $status = 302): RouteRegistrar
    {
        return self::get($from, function () use ($to, $status) {
            header("Location: {$to}", true, $status);
            exit;
        });
    }

    /**
     * Register a permanent redirect (301)
     */
    public static function permanentRedirect(string $from, string $to): RouteRegistrar
    {
        return self::redirect($from, $to, 301);
    }

    /**
     * Register a view route (renders a template directly)
     */
    public static function view(string $path, string $template, array $data = []): RouteRegistrar
    {
        return self::get($path, function () use ($template, $data) {
            return view($template, $data);
        });
    }

    /**
     * Add a route to the router
     */
    public static function addRoute($methods, string $path, $callback, array $middlewares = []): RouteRegistrar
    {
        $path = self::$prefix . $path;
        $middlewares = array_merge(self::$groupMiddleware, $middlewares);

        // Parse string callback (e.g., 'UserController@show')
        $callback = self::parseCallback($callback);

        $registrar = new RouteRegistrar();

        foreach ((array) $methods as $method) {
            $method = strtoupper($method);
            self::$routes[$method][$path] = [
                'callback' => $callback,
                'middleware' => $middlewares,
                'pattern' => self::compileRoutePattern($path),
                'registrar' => $registrar,
                'domain' => self::$currentDomain,
                'constraints' => [],
            ];
        }

        $registrar->setPath($path);
        $registrar->setMethods((array) $methods);

        return $registrar;
    }

    /**
     * Parse callback from string syntax to callable
     * Supports: 'Controller@method', [Controller::class, 'method'], Closure
     */
    protected static function parseCallback($callback): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$controller, $method] = explode('@', $callback, 2);

            // Support namespaced controllers
            if (!class_exists($controller)) {
                $controller = "App\\Controllers\\{$controller}";
            }

            if (!class_exists($controller)) {
                throw new \InvalidArgumentException("Controller class [{$controller}] not found.");
            }

            // Use container for dependency injection
            $container = AppContainer::getInstance();
            $instance = $container->has($controller)
                ? $container->get($controller)
                : new $controller();

            return [$instance, $method];
        }

        // Support [Controller::class, 'method'] syntax
        if (is_array($callback) && count($callback) === 2 && is_string($callback[0])) {
            $controller = $callback[0];
            $method = $callback[1];

            if (!class_exists($controller)) {
                throw new \InvalidArgumentException("Controller class [{$controller}] not found.");
            }

            $container = AppContainer::getInstance();
            $instance = $container->has($controller)
                ? $container->get($controller)
                : new $controller();

            return [$instance, $method];
        }

        return $callback;
    }

    // ========================================================================
    // ROUTE GROUPS
    // ========================================================================

    /**
     * Create a route group
     */
    public static function group(string|array $attributes, callable $callback): void
    {
        // Support both string prefix and array of attributes
        if (is_string($attributes)) {
            $attributes = ['prefix' => $attributes];
        }

        $previousPrefix = self::$prefix;
        $previousMiddleware = self::$groupMiddleware;
        $previousDomain = self::$currentDomain;

        // Apply prefix
        if (isset($attributes['prefix'])) {
            self::$prefix .= $attributes['prefix'];
        }

        // Apply middleware
        if (isset($attributes['middleware'])) {
            $middleware = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];
            self::$groupMiddleware = array_merge(self::$groupMiddleware, $middleware);
        }

        // Apply domain
        if (isset($attributes['domain'])) {
            self::$currentDomain = $attributes['domain'];
        }

        call_user_func($callback);

        self::$prefix = $previousPrefix;
        self::$groupMiddleware = $previousMiddleware;
        self::$currentDomain = $previousDomain;
    }

    /**
     * Create a route group with prefix
     */
    public static function prefix(string $prefix): PendingRouteGroup
    {
        return new PendingRouteGroup(['prefix' => $prefix]);
    }

    /**
     * Create a route group with middleware
     */
    public static function middlewareGroup($middleware): PendingRouteGroup
    {
        return new PendingRouteGroup(['middleware' => $middleware]);
    }

    /**
     * Create a route group for a domain
     */
    public static function domain(string $domain): PendingRouteGroup
    {
        return new PendingRouteGroup(['domain' => $domain]);
    }

    // ========================================================================
    // RESOURCE ROUTES
    // ========================================================================

    /**
     * Register resource routes (full CRUD)
     */
    public static function resource(string $name, string $controller, array $options = []): void
    {
        $singular = rtrim($name, 's');
        $only = $options['only'] ?? null;
        $except = $options['except'] ?? [];
        $middleware = $options['middleware'] ?? [];

        $routes = [
            'index' => ['GET', "/{$name}", 'index'],
            'create' => ['GET', "/{$name}/create", 'create'],
            'store' => ['POST', "/{$name}", 'store'],
            'show' => ['GET', "/{$name}/{{$singular}}", 'show'],
            'edit' => ['GET', "/{$name}/{{$singular}}/edit", 'edit'],
            'update' => ['PUT', "/{$name}/{{$singular}}", 'update'],
            'destroy' => ['DELETE', "/{$name}/{{$singular}}", 'destroy'],
        ];

        foreach ($routes as $action => [$method, $path, $controllerMethod]) {
            if ($only !== null && !in_array($action, $only)) {
                continue;
            }
            if (in_array($action, $except)) {
                continue;
            }

            self::addRoute([$method], $path, "{$controller}@{$controllerMethod}", $middleware)
                ->name("{$name}.{$action}");

            // Add PATCH for update as well
            if ($action === 'update') {
                self::addRoute(['PATCH'], $path, "{$controller}@{$controllerMethod}", $middleware)
                    ->name("{$name}.{$action}.patch");
            }
        }
    }

    /**
     * Register API resource routes (without create/edit)
     */
    public static function apiResource(string $name, string $controller, array $options = []): void
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);
        self::resource($name, $controller, $options);
    }

    /**
     * Register multiple resources at once
     */
    public static function resources(array $resources): void
    {
        foreach ($resources as $name => $controller) {
            self::resource($name, $controller);
        }
    }

    /**
     * Register multiple API resources at once
     */
    public static function apiResources(array $resources): void
    {
        foreach ($resources as $name => $controller) {
            self::apiResource($name, $controller);
        }
    }

    // ========================================================================
    // FALLBACK ROUTE
    // ========================================================================

    /**
     * Register a fallback route for unmatched requests
     */
    public static function fallback($callback): void
    {
        self::$fallbackRoute = [
            'callback' => self::parseCallback($callback),
            'middleware' => self::$groupMiddleware,
        ];
    }

    // ========================================================================
    // GLOBAL PATTERNS
    // ========================================================================

    /**
     * Define a global pattern for a parameter
     */
    public static function pattern(string $name, string $pattern): void
    {
        self::$patterns[$name] = $pattern;
    }

    /**
     * Define multiple global patterns
     */
    public static function patterns(array $patterns): void
    {
        foreach ($patterns as $name => $pattern) {
            self::$patterns[$name] = $pattern;
        }
    }

    // ========================================================================
    // MODEL BINDING
    // ========================================================================

    /**
     * Register a model binding
     */
    public static function model(string $key, string $class, ?string $column = null): void
    {
        self::$modelBindings[$key] = [
            'class' => $class,
            'column' => $column ?? 'id',
        ];
    }

    /**
     * Register a custom binding resolver
     */
    public static function bind(string $key, Closure $callback): void
    {
        self::$modelBindings[$key] = [
            'resolver' => $callback,
        ];
    }

    /**
     * Resolve model bindings for parameters
     */
    protected static function resolveModelBindings(array &$parameters): void
    {
        foreach ($parameters as $key => $value) {
            if (!isset(self::$modelBindings[$key])) {
                continue;
            }

            $binding = self::$modelBindings[$key];

            if (isset($binding['resolver'])) {
                $parameters[$key] = ($binding['resolver'])($value);
            } elseif (isset($binding['class'])) {
                $class = $binding['class'];
                $column = $binding['column'];

                if (method_exists($class, 'find')) {
                    $model = $class::find($value);
                } elseif (method_exists($class, 'query')) {
                    $model = $class::query()->where($column, '=', $value)->first();
                } else {
                    continue;
                }

                if (!$model) {
                    throw HttpException::notFound("No query results for model [{$class}] with {$column} = {$value}");
                }

                $parameters[$key] = $model;
            }
        }
    }

    // ========================================================================
    // MIDDLEWARE
    // ========================================================================

    /**
     * Add global middleware
     */
    public static function middleware($middleware): void
    {
        if (is_array($middleware)) {
            self::$middlewares = array_merge(self::$middlewares, $middleware);
        } else {
            self::$middlewares[] = $middleware;
        }
    }

    // ========================================================================
    // NAMED ROUTES & URL GENERATION
    // ========================================================================

    /**
     * Register a named route
     */
    public static function name(string $name, string $path): void
    {
        self::$namedRoutes[$name] = $path;
    }

    /**
     * Check if a named route exists
     */
    public static function has(string $name): bool
    {
        return isset(self::$namedRoutes[$name]);
    }

    /**
     * Generate URL for a named route
     */
    public static function url(string $name, array $parameters = [], bool $absolute = true): string
    {
        if (!isset(self::$namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $url = self::$namedRoutes[$name];

        // Replace route parameters
        foreach ($parameters as $key => $value) {
            $url = preg_replace("/\{{$key}\??(?::[^}]+)?\}/", (string) $value, $url);
        }

        // Remove any remaining optional parameters
        $url = preg_replace('/\{[^}]+\?\}/', '', $url);

        // Clean up any remaining required parameters (error if still present)
        if (preg_match('/\{([^}]+)\}/', $url, $matches)) {
            throw new \InvalidArgumentException("Missing required parameter [{$matches[1]}] for route [{$name}].");
        }

        $url = rtrim($url, '/') ?: '/';

        if ($absolute) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = "{$scheme}://{$host}{$url}";
        }

        return $url;
    }

    /**
     * Get current route name
     */
    public static function currentRouteName(): ?string
    {
        $path = self::getPathFromServer();
        $method = self::getMethodFromServer();

        foreach (self::$namedRoutes as $name => $routePath) {
            if (isset(self::$routes[$method][$routePath])) {
                $pattern = self::$routes[$method][$routePath]['pattern'];
                $basePath = preg_quote($_ENV["BASE_PATH"] ?? '', "#");
                $cleanPath = preg_replace("#^{$basePath}#", "", $path);

                if (preg_match($pattern, $cleanPath)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Check if current route matches a name or pattern
     */
    public static function is(string ...$patterns): bool
    {
        $currentName = self::currentRouteName();

        if (!$currentName) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($pattern === $currentName) {
                return true;
            }

            // Support wildcard patterns like 'admin.*'
            if (str_contains($pattern, '*')) {
                $regex = str_replace('.', '\.', $pattern);
                $regex = str_replace('*', '.*', $regex);
                if (preg_match("/^{$regex}$/", $currentName)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ========================================================================
    // DISPATCH
    // ========================================================================

    /**
     * Dispatch the current request
     */
    public static function dispatch(): void
    {
        $path = self::getPathFromServer();
        $method = self::getMethodFromServer();
        $queryParams = $_GET;

        // HTTP method override
        if ($method === 'POST') {
            $method = strtoupper($_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? 'POST');
        }

        $callbackInfo = self::findCallback($method, $path, $queryParams);

        if ($callbackInfo) {
            [$callback, $parameters, $statusCode, $routeMiddleware] = $callbackInfo;
            http_response_code($statusCode);

            // Resolve model bindings
            self::resolveModelBindings($parameters);

            // Run global middleware
            self::runMiddlewareChain($parameters, self::$middlewares);

            // Run route middleware
            if ($routeMiddleware) {
                self::runMiddlewareChain($parameters, $routeMiddleware);
            }

            // Autowire controller parameters
            if (is_array($callback) && is_object($callback[0])) {
                $controller = $callback[0];
                $methodName = $callback[1];

                $reflector = new \ReflectionMethod($controller, $methodName);
                $params = $reflector->getParameters();

                $parameters = self::autowireParameters($params, $parameters);
            }

            $response = call_user_func_array($callback, $parameters);

            self::sendResponse($response);
        } elseif (self::$fallbackRoute) {
            // Use fallback route
            $callback = self::$fallbackRoute['callback'];
            $middleware = self::$fallbackRoute['middleware'];

            self::runMiddlewareChain([], self::$middlewares);
            self::runMiddlewareChain([], $middleware);

            $response = call_user_func($callback);

            self::sendResponse($response);
        } else {
            $allowedMethods = self::getAllowedMethods($path);

            if (!empty($allowedMethods)) {
                throw HttpException::methodNotAllowed(
                    "Method {$method} not allowed. Allowed: " . implode(', ', $allowedMethods)
                );
            }

            throw HttpException::notFound("Route not found: {$path}");
        }
    }

    // ========================================================================
    // INTERNAL METHODS
    // ========================================================================

    /**
     * Send the response to the client
     * Handles both Response objects and raw string/null values
     */
    private static function sendResponse(mixed $response): void
    {
        if ($response === null) {
            return;
        }

        if ($response instanceof Response) {
            $response->send();
        } else {
            // For backwards compatibility with string responses
            echo $response;
        }
    }

    private static function getAllowedMethods(string $path): array
    {
        $allowed = [];
        $basePath = preg_quote($_ENV["BASE_PATH"] ?? '', "#");
        $cleanPath = preg_replace("#^{$basePath}#", "", $path);

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $routePath => $routeInfo) {
                if (preg_match($routeInfo['pattern'], $cleanPath)) {
                    $allowed[] = $method;
                }
            }
        }

        return array_unique($allowed);
    }

    private static function runMiddlewareChain(array &$parameters, array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            if (is_callable($middleware)) {
                call_user_func_array($middleware, [&$parameters]);
            } elseif (is_string($middleware) && class_exists($middleware)) {
                $instance = new $middleware();
                if (method_exists($instance, 'handle')) {
                    $instance->handle($parameters);
                }
            }
        }
    }

    private static function autowireParameters(array $reflectionParams, array $routeParams): array
    {
        $autowiredParams = [];

        foreach ($reflectionParams as $param) {
            $type = $param->getType();
            $className = $type && !$type->isBuiltin() ? $type->getName() : null;

            // Check if we have a model-bound parameter with this name
            $paramName = $param->getName();
            if (isset($routeParams[$paramName]) && is_object($routeParams[$paramName])) {
                $autowiredParams[] = $routeParams[$paramName];
                continue;
            }

            if ($className) {
                $container = AppContainer::getInstance();
                try {
                    $autowiredParams[] = $container->get($className);
                } catch (\Exception $e) {
                    if (class_exists($className)) {
                        $autowiredParams[] = new $className();
                    } elseif ($param->isDefaultValueAvailable()) {
                        $autowiredParams[] = $param->getDefaultValue();
                    } else {
                        $autowiredParams[] = null;
                    }
                }
            } else {
                if (isset($routeParams[$paramName])) {
                    $autowiredParams[] = $routeParams[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $autowiredParams[] = $param->getDefaultValue();
                } else {
                    $autowiredParams[] = null;
                }
            }
        }

        return $autowiredParams;
    }

    private static function getPathFromServer(): string
    {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    }

    private static function getMethodFromServer(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    private static function findCallback(string $method, string $path, array &$parameters): ?array
    {
        if (!isset(self::$routes[$method])) {
            return null;
        }

        $cacheKey = "{$method}:{$path}";
        if (isset(self::$routeCache[$cacheKey])) {
            return self::$routeCache[$cacheKey];
        }

        $basePath = preg_quote($_ENV["BASE_PATH"] ?? '', "#");
        $result = preg_replace("#^{$basePath}#", "", $path);

        foreach (self::$routes[$method] as $routePath => $routeInfo) {
            // Check domain if specified
            if ($routeInfo['domain'] && isset($_SERVER['HTTP_HOST'])) {
                $domainPattern = str_replace('*', '([^.]+)', $routeInfo['domain']);
                if (!preg_match("/^{$domainPattern}$/", $_SERVER['HTTP_HOST'])) {
                    continue;
                }
            }

            if (preg_match($routeInfo['pattern'], $result, $matches)) {
                $parameters = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $statusCode = (int) ($parameters['_status'] ?? 200);
                unset($parameters['_status'], $parameters['_lang']);

                self::$routeCache[$cacheKey] = [
                    $routeInfo['callback'],
                    $parameters,
                    $statusCode,
                    $routeInfo['middleware']
                ];

                return self::$routeCache[$cacheKey];
            }
        }

        return null;
    }

    private static function compileRoutePattern(string $path): string
    {
        $routeParts = explode('/', trim($path, '/'));
        $pattern = '#^';

        foreach ($routeParts as $part) {
            if ($part === '') {
                continue;
            }

            if (strpos($part, '{') === 0 && strpos($part, '}') === strlen($part) - 1) {
                $paramName = substr($part, 1, -1);
                $optional = false;
                $constraint = '[^/]+';

                if (strpos($paramName, '?') === strlen($paramName) - 1) {
                    $paramName = substr($paramName, 0, -1);
                    $optional = true;
                }

                if (strpos($paramName, ':') !== false) {
                    [$paramName, $constraint] = explode(':', $paramName, 2);
                }

                // Check for global pattern
                if (isset(self::$patterns[$paramName])) {
                    $constraint = self::$patterns[$paramName];
                }

                if ($optional) {
                    $pattern .= "(?:/(?P<{$paramName}>{$constraint}))?";
                } else {
                    $pattern .= "/(?P<{$paramName}>{$constraint})";
                }
            } else {
                $pattern .= '/' . preg_quote($part, '#');
            }
        }

        if ($pattern === '#^') {
            $pattern .= '/';
        }

        return $pattern . '/?$#';
    }

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Get all registered routes
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Get all named routes
     */
    public static function getNamedRoutes(): array
    {
        return self::$namedRoutes;
    }

    /**
     * Clear route cache
     */
    public static function clearCache(): void
    {
        self::$routeCache = [];
    }

    /**
     * Reset all routes
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$namedRoutes = [];
        self::$middlewares = [];
        self::$groupMiddleware = [];
        self::$prefix = '';
        self::$routeCache = [];
        self::$fallbackRoute = null;
        self::$currentDomain = null;
        self::$modelBindings = [];
        self::$patterns = [];
    }

    /**
     * List all routes (for debugging/CLI)
     */
    public static function list(): array
    {
        $routes = [];

        foreach (self::$routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $info) {
                $name = array_search($path, self::$namedRoutes) ?: null;

                $routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'name' => $name,
                    'middleware' => $info['middleware'],
                    'domain' => $info['domain'],
                ];
            }
        }

        return $routes;
    }
}

/**
 * Pending Route Group Builder
 *
 * Provides fluent interface for building route groups.
 */
class PendingRouteGroup
{
    private array $attributes = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Add prefix to group
     */
    public function prefix(string $prefix): self
    {
        $this->attributes['prefix'] = ($this->attributes['prefix'] ?? '') . $prefix;
        return $this;
    }

    /**
     * Add middleware to group
     */
    public function middleware($middleware): self
    {
        $existing = $this->attributes['middleware'] ?? [];
        $new = is_array($middleware) ? $middleware : [$middleware];
        $this->attributes['middleware'] = array_merge($existing, $new);
        return $this;
    }

    /**
     * Set domain for group
     */
    public function domain(string $domain): self
    {
        $this->attributes['domain'] = $domain;
        return $this;
    }

    /**
     * Define the routes in the group
     */
    public function group(callable $callback): void
    {
        Route::group($this->attributes, $callback);
    }
}

/**
 * Route Registrar
 *
 * Provides fluent interface for route configuration.
 */
class RouteRegistrar
{
    private string $path = '';
    private array $methods = [];
    private array $constraints = [];

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function setMethods(array $methods): void
    {
        $this->methods = $methods;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        Route::name($name, $this->path);
        return $this;
    }

    /**
     * Add middleware to route
     */
    public function middleware($middleware): self
    {
        // This would need to update the route's middleware
        // For now, middleware should be passed when defining the route
        return $this;
    }

    // ========================================================================
    // CONSTRAINT HELPERS
    // ========================================================================

    /**
     * Constrain parameter to numeric values
     */
    public function whereNumber(string ...$parameters): self
    {
        foreach ($parameters as $param) {
            $this->constraints[$param] = '\d+';
        }
        return $this;
    }

    /**
     * Constrain parameter to alphabetic values
     */
    public function whereAlpha(string ...$parameters): self
    {
        foreach ($parameters as $param) {
            $this->constraints[$param] = '[a-zA-Z]+';
        }
        return $this;
    }

    /**
     * Constrain parameter to alphanumeric values
     */
    public function whereAlphaNumeric(string ...$parameters): self
    {
        foreach ($parameters as $param) {
            $this->constraints[$param] = '[a-zA-Z0-9]+';
        }
        return $this;
    }

    /**
     * Constrain parameter to UUID format
     */
    public function whereUuid(string ...$parameters): self
    {
        foreach ($parameters as $param) {
            $this->constraints[$param] = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        }
        return $this;
    }

    /**
     * Constrain parameter to slug format
     */
    public function whereSlug(string ...$parameters): self
    {
        foreach ($parameters as $param) {
            $this->constraints[$param] = '[a-z0-9-]+';
        }
        return $this;
    }

    /**
     * Constrain parameter to specific values
     */
    public function whereIn(string $parameter, array $values): self
    {
        $this->constraints[$parameter] = implode('|', array_map('preg_quote', $values));
        return $this;
    }

    /**
     * Set custom constraint pattern
     */
    public function where(string $parameter, string $pattern): self
    {
        $this->constraints[$parameter] = $pattern;
        return $this;
    }
}
