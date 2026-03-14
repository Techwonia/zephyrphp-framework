<?php

declare(strict_types=1);

use ZephyrPHP\Core\Application;
use ZephyrPHP\Core\Http\Request;
use ZephyrPHP\Core\Http\Response;
use ZephyrPHP\Session\Session;
use ZephyrPHP\Log\LogManager;
use ZephyrPHP\Router\Route;
use ZephyrPHP\Security\Csrf;
use ZephyrPHP\View\View;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Asset\Asset;
use ZephyrPHP\Container\Container;

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return Application::getInstance();
        }

        return $container->make($abstract, $parameters);
    }
}

if (!function_exists('container')) {
    function container(): Container
    {
        return Container::getInstance();
    }
}

if (!function_exists('resolve')) {
    function resolve(string $abstract, array $parameters = []): mixed
    {
        return Container::getInstance()->make($abstract, $parameters);
    }
}

if (!function_exists('singleton')) {
    function singleton(string $abstract, mixed $concrete = null): void
    {
        Container::getInstance()->singleton($abstract, $concrete);
    }
}

if (!function_exists('bind')) {
    function bind(string $abstract, mixed $concrete = null): void
    {
        Container::getInstance()->bind($abstract, $concrete);
    }
}

if (!function_exists('tagged')) {
    function tagged(string $tag): array
    {
        return Container::getInstance()->tagged($tag);
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, $default = null)
    {
        $request = Request::getInstance();

        if ($key === null) {
            return $request;
        }

        return $request->input($key, $default);
    }
}

if (!function_exists('response')) {
    function response(string $content = '', int $statusCode = 200, array $headers = []): Response
    {
        return Response::make($content, $statusCode, $headers);
    }
}

if (!function_exists('json')) {
    function json($data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }
}

if (!function_exists('session')) {
    function session(?string $key = null, $default = null)
    {
        $session = Session::getInstance();

        if ($key === null) {
            return $session;
        }

        return $session->get($key, $default);
    }
}

if (!function_exists('flash')) {
    /**
     * Get or set flash data
     *
     * Usage:
     *   flash('success', 'Record created!') - Set flash message
     *   flash('success')                    - Get flash message
     *   flash()                             - Get Flash instance
     */
    function flash(?string $key = null, $value = null)
    {
        if ($key === null) {
            return new class {
                public function success(string $message): void { \ZephyrPHP\Session\Flash::success($message); }
                public function error(string $message): void { \ZephyrPHP\Session\Flash::error($message); }
                public function warning(string $message): void { \ZephyrPHP\Session\Flash::warning($message); }
                public function info(string $message): void { \ZephyrPHP\Session\Flash::info($message); }
                public function errors(array $errors): void { \ZephyrPHP\Session\Flash::errors($errors); }
                public function old(array $input): void { \ZephyrPHP\Session\Flash::old($input); }
            };
        }

        if ($value === null) {
            return \ZephyrPHP\Session\Flash::get($key);
        }

        \ZephyrPHP\Session\Flash::set($key, $value);
    }
}

if (!function_exists('old')) {
    function old(string $key, $default = null)
    {
        return Request::getInstance()->old($key, $default);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $statusCode = 302): never
    {
        (new Response())->redirect($url, $statusCode);
    }
}

if (!function_exists('back')) {
    function back(string $fallback = '/'): never
    {
        (new Response())->back($fallback);
    }
}

if (!function_exists('route')) {
    function route(string $name, array $parameters = []): string
    {
        return Route::url($name, $parameters);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $baseUrl = get_base_url();
        return $baseUrl . '/' . ltrim($path, '/');
    }
}

if (!function_exists('get_base_url')) {
    function get_base_url(): string
    {
        // Auto-detect from request
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        // Fallback to APP_URL or localhost
        $appUrl = $_ENV['APP_URL'] ?? '';
        if (!empty($appUrl)) {
            return rtrim($appUrl, '/');
        }

        return 'http://localhost';
    }
}

if (!function_exists('asset')) {
    function asset(string $path, array $options = []): string
    {
        return Asset::url($path, $options);
    }
}

if (!function_exists('css')) {
    function css(string $path, array $attributes = []): string
    {
        return Asset::css($path, $attributes);
    }
}

if (!function_exists('js')) {
    function js(string $path, array $attributes = []): string
    {
        return Asset::js($path, $attributes);
    }
}

if (!function_exists('image')) {
    function image(string $path, ?string $alt = null, array $attributes = []): string
    {
        return Asset::image($path, $alt, $attributes);
    }
}

if (!function_exists('view')) {
    /**
     * Render a view template and return a Response object
     *
     * @param string $template Template name (without extension)
     * @param array $variables Variables to pass to the template
     * @param int $statusCode HTTP status code (default 200)
     * @return Response
     */
    function view(string $template, array $variables = [], int $statusCode = 200): Response
    {
        $content = View::getInstance()->render($template, $variables);
        return Response::html($content, $statusCode);
    }
}

if (!function_exists('logger')) {
    function logger(?string $channel = null): LogManager
    {
        $manager = LogManager::getInstance();

        if ($channel !== null) {
            $manager->setDefaultChannel($channel);
        }

        return $manager;
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // Only process string values for special keywords
        if (is_string($value)) {
            switch (strtolower($value)) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'null':
                case '(null)':
                    return null;
                case 'empty':
                case '(empty)':
                    return '';
            }
        }

        return $value;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::getToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::getHiddenInput();
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string
    {
        return Csrf::getMetaTag();
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new \ZephyrPHP\Exception\HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    function abort_if(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    function abort_unless(bool $condition, int $code, string $message = ''): void
    {
        if (!$condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('dd')) {
    function dd(...$vars): never
    {
        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;margin:8px;border-radius:4px;font-family:Consolas,monospace;font-size:13px;overflow:auto;">';
            var_dump($var);
            echo '</pre>';
        }
        exit(1);
    }
}

if (!function_exists('dump')) {
    function dump(...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;margin:8px;border-radius:4px;font-family:Consolas,monospace;font-size:13px;overflow:auto;">';
            var_dump($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('now')) {
    function now(): \DateTime
    {
        return new \DateTime();
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('class_basename')) {
    function class_basename(object|string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('value')) {
    function value($value)
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

if (!function_exists('filled')) {
    function filled($value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return count($value) > 0;
        }

        return $value !== null;
    }
}

if (!function_exists('blank')) {
    function blank($value): bool
    {
        return !filled($value);
    }
}

if (!function_exists('collect')) {
    function collect(array $items = []): array
    {
        return $items;
    }
}

if (!function_exists('tap')) {
    function tap($value, callable $callback)
    {
        $callback($value);
        return $value;
    }
}

if (!function_exists('retry')) {
    function retry(int $times, callable $callback, int $sleepMilliseconds = 0)
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $times) {
            $attempts++;

            try {
                return $callback($attempts);
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($attempts < $times && $sleepMilliseconds > 0) {
                    usleep($sleepMilliseconds * 1000);
                }
            }
        }

        throw $lastException;
    }
}

// ============================================================================
// ENVIRONMENT DETECTION HELPERS
// ============================================================================

if (!function_exists('environment')) {
    /**
     * Get the current environment name or check if it matches.
     *
     * Usage:
     *   environment()              // "production"
     *   environment('production')  // true
     *   environment('dev', 'local') // true if either matches
     */
    function environment(string ...$environments): string|bool
    {
        $current = $_ENV['ENV'] ?? 'dev';

        if (empty($environments)) {
            return $current;
        }

        return in_array($current, $environments, true);
    }
}

if (!function_exists('is_production')) {
    function is_production(): bool
    {
        return environment('production', 'prod');
    }
}

if (!function_exists('is_local')) {
    function is_local(): bool
    {
        return environment('local', 'dev', 'development');
    }
}

if (!function_exists('is_testing')) {
    function is_testing(): bool
    {
        return environment('testing', 'test');
    }
}

if (!function_exists('is_staging')) {
    function is_staging(): bool
    {
        return environment('staging', 'stage');
    }
}

if (!function_exists('debug_mode')) {
    /**
     * Check if debug mode is enabled.
     */
    function debug_mode(): bool
    {
        return filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}

// ============================================================================
// TRANSLATION HELPERS
// ============================================================================

if (!function_exists('__')) {
    /**
     * Translate a string.
     *
     * Usage:
     *   __('messages.welcome')
     *   __('messages.hello', ['name' => 'John'])
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        if (class_exists(\ZephyrPHP\Translation\Translator::class)) {
            return \ZephyrPHP\Translation\Translator::getInstance()->get($key, $replace, $locale);
        }
        return $key;
    }
}

if (!function_exists('trans_choice')) {
    /**
     * Translate with pluralization.
     */
    function trans_choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        if (class_exists(\ZephyrPHP\Translation\Translator::class)) {
            return \ZephyrPHP\Translation\Translator::getInstance()->choice($key, $count, $replace, $locale);
        }
        return $key;
    }
}

// ============================================================================
// EVENT & HOOK HELPERS
// ============================================================================

if (!function_exists('event')) {
    /**
     * Dispatch an event to all registered listeners.
     *
     * @param \ZephyrPHP\Event\Event $event The event to dispatch
     * @return \ZephyrPHP\Event\Event The dispatched event
     */
    function event(\ZephyrPHP\Event\Event $event): \ZephyrPHP\Event\Event
    {
        return \ZephyrPHP\Event\EventDispatcher::getInstance()->dispatch($event);
    }
}

if (!function_exists('listen')) {
    /**
     * Register a listener for an event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @param callable|string $listener Callable or "Class@method" string
     * @param int $priority Lower = earlier (default 0)
     */
    function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        \ZephyrPHP\Event\EventDispatcher::getInstance()->listen($eventClass, $listener, $priority);
    }
}

if (!function_exists('action')) {
    /**
     * Register a callback for an action hook.
     *
     * @param string $hook Hook name (e.g., 'page.saved')
     * @param callable $callback The callback to execute
     * @param int $priority Lower = earlier (default 10)
     */
    function action(string $hook, callable $callback, int $priority = 10): void
    {
        \ZephyrPHP\Hook\HookManager::getInstance()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    /**
     * Execute all callbacks for an action hook.
     *
     * @param string $hook Hook name
     * @param mixed ...$args Arguments to pass to callbacks
     */
    function do_action(string $hook, mixed ...$args): void
    {
        \ZephyrPHP\Hook\HookManager::getInstance()->doAction($hook, ...$args);
    }
}

if (!function_exists('filter')) {
    /**
     * Register a callback for a filter hook.
     *
     * @param string $hook Hook name (e.g., 'page.content')
     * @param callable $callback Receives value, returns modified value
     * @param int $priority Lower = earlier (default 10)
     */
    function filter(string $hook, callable $callback, int $priority = 10): void
    {
        \ZephyrPHP\Hook\HookManager::getInstance()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filter')) {
    /**
     * Apply all filter callbacks to a value.
     *
     * @param string $hook Hook name
     * @param mixed $value The value to filter
     * @param mixed ...$args Additional arguments
     * @return mixed The filtered value
     */
    function apply_filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        return \ZephyrPHP\Hook\HookManager::getInstance()->applyFilter($hook, $value, ...$args);
    }
}

// ============================================================================
// MODULE HELPERS
// ============================================================================

if (!function_exists('modules')) {
    /**
     * Get the module manager instance
     */
    function modules(): \ZephyrPHP\Module\ModuleManager
    {
        return \ZephyrPHP\Module\ModuleManager::getInstance();
    }
}

if (!function_exists('has_module')) {
    /**
     * Check if a module is enabled
     */
    function has_module(string $name): bool
    {
        return \ZephyrPHP\Module\ModuleManager::getInstance()->isEnabled($name);
    }
}

if (!function_exists('require_module')) {
    /**
     * Require a module (throws exception if not enabled)
     *
     * @throws \ZephyrPHP\Module\ModuleNotEnabledException
     */
    function require_module(string $name): \ZephyrPHP\Module\ModuleInterface
    {
        return \ZephyrPHP\Module\ModuleManager::getInstance()->require($name);
    }
}

// ============================================================================
// AUTH HELPERS (require auth module)
// ============================================================================

if (!function_exists('auth')) {
    /**
     * Get the Auth instance (requires auth module)
     */
    function auth(): ?\ZephyrPHP\Auth\Auth
    {
        if (!has_module('auth')) {
            return null;
        }

        return \ZephyrPHP\Container\Container::getInstance()->make(\ZephyrPHP\Auth\Auth::class);
    }
}

if (!function_exists('user')) {
    /**
     * Get the currently authenticated user
     */
    function user(): ?object
    {
        $auth = auth();
        return $auth?->user();
    }
}

if (!function_exists('guest')) {
    /**
     * Check if the current user is a guest (not authenticated)
     */
    function guest(): bool
    {
        $auth = auth();
        return $auth === null || $auth->guest();
    }
}

// ============================================================================
// AUTHORIZATION HELPERS (require authorization module)
// ============================================================================

if (!function_exists('can')) {
    /**
     * Check if the current user can perform an ability
     */
    function can(string $ability, mixed ...$arguments): bool
    {
        if (!has_module('authorization')) {
            return false;
        }

        // Use authorization module's Gate
        if (class_exists(\ZephyrPHP\Authorization\Gate::class)) {
            return \ZephyrPHP\Authorization\Gate::allows($ability, ...$arguments);
        }

        return false;
    }
}

if (!function_exists('cannot')) {
    /**
     * Check if the current user cannot perform an ability
     */
    function cannot(string $ability, mixed ...$arguments): bool
    {
        return !can($ability, ...$arguments);
    }
}

if (!function_exists('authorize')) {
    /**
     * Authorize an ability (throws exception if denied)
     *
     * @throws \ZephyrPHP\Authorization\AuthorizationException
     */
    function authorize(string $ability, mixed ...$arguments): void
    {
        if (!has_module('authorization')) {
            if (class_exists(\ZephyrPHP\Authorization\AuthorizationException::class)) {
                throw new \ZephyrPHP\Authorization\AuthorizationException("Authorization module is not enabled.");
            }
            throw new \RuntimeException("Authorization module is not enabled.");
        }

        if (class_exists(\ZephyrPHP\Authorization\Gate::class)) {
            \ZephyrPHP\Authorization\Gate::authorize($ability, ...$arguments);
        }
    }
}

// ============================================================================
// CACHE HELPERS (require cache module)
// ============================================================================

if (!function_exists('cache')) {
    /**
     * Get a value from cache or store it
     */
    function cache(?string $key = null, mixed $default = null)
    {
        if (!has_module('cache')) {
            return $default;
        }

        $cache = \ZephyrPHP\Container\Container::getInstance()->make('cache');

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

// ============================================================================
// QUEUE HELPERS (require queue module)
// ============================================================================

if (!function_exists('dispatch')) {
    /**
     * Dispatch a job to the queue
     */
    function dispatch(string|object $job, array $data = []): string|bool
    {
        if (!has_module('queue')) {
            // If queue module not enabled, execute synchronously
            if (is_object($job) && method_exists($job, 'handle')) {
                $job->handle();
                return true;
            }
            return false;
        }

        $queue = \ZephyrPHP\Container\Container::getInstance()->make('queue');
        return $queue->push($job, $data);
    }
}

if (!function_exists('dispatch_later')) {
    /**
     * Dispatch a job with delay
     */
    function dispatch_later(int $delay, string|object $job, array $data = []): string|bool
    {
        if (!has_module('queue')) {
            return dispatch($job, $data);
        }

        $queue = \ZephyrPHP\Container\Container::getInstance()->make('queue');
        return $queue->later($delay, $job, $data);
    }
}

// ============================================================================
// MAIL HELPERS (require mail module)
// ============================================================================

if (!function_exists('mail')) {
    /**
     * Get the mailer instance
     */
    function mail(): ?object
    {
        if (!has_module('mail')) {
            return null;
        }

        return \ZephyrPHP\Container\Container::getInstance()->make('mail');
    }
}

// ============================================================================
// VALIDATION HELPERS (require validation module)
// ============================================================================

if (!function_exists('validate')) {
    /**
     * Validate data against rules
     */
    function validate(array $data, array $rules, array $messages = []): \ZephyrPHP\Validation\Validator
    {
        return new \ZephyrPHP\Validation\Validator($data, $rules, $messages);
    }
}

// ============================================================================
// HASH HELPERS
// ============================================================================

if (!function_exists('bcrypt')) {
    /**
     * Hash a password using bcrypt
     */
    function bcrypt(string $value): string
    {
        return \ZephyrPHP\Security\Hash::make($value);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Check if a value matches a hash
     */
    function hash_check(string $value, string $hash): bool
    {
        return \ZephyrPHP\Security\Hash::check($value, $hash);
    }
}

// ============================================================================
// ENCRYPTION HELPERS
// ============================================================================

if (!function_exists('encrypt')) {
    /**
     * Encrypt a value
     */
    function encrypt(string $value): string
    {
        return \ZephyrPHP\Security\Encryption::encrypt($value);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decrypt a value
     */
    function decrypt(string $encrypted): ?string
    {
        return \ZephyrPHP\Security\Encryption::decrypt($encrypted);
    }
}
