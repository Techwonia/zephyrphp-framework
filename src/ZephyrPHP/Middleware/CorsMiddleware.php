<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Config\Config;

/**
 * CORS (Cross-Origin Resource Sharing) middleware.
 *
 * Handles preflight OPTIONS requests and sets appropriate CORS headers.
 *
 * Configuration (config/cors.php):
 *   return [
 *       'allowed_origins'   => ['*'],
 *       'allowed_methods'   => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
 *       'allowed_headers'   => ['Content-Type', 'Authorization', 'X-Requested-With'],
 *       'exposed_headers'   => [],
 *       'max_age'           => 86400,
 *       'supports_credentials' => false,
 *       'paths'             => ['api/*'],
 *   ];
 */
class CorsMiddleware implements MiddlewareInterface
{
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::get('cors', []);
    }

    public function handle($request, callable $next)
    {
        // Check if this request path should have CORS applied
        if (!$this->shouldApply()) {
            return $next($request);
        }

        // Handle preflight OPTIONS request
        if ($this->isPreflightRequest()) {
            $this->sendPreflightHeaders();
            http_response_code(204);
            return null;
        }

        // Set CORS headers for actual request
        $this->sendCorsHeaders();

        return $next($request);
    }

    /**
     * Check if CORS should be applied to the current request path.
     */
    protected function shouldApply(): bool
    {
        $paths = $this->config['paths'] ?? ['*'];

        if (in_array('*', $paths, true)) {
            return true;
        }

        $currentPath = $_SERVER['REQUEST_URI'] ?? '/';
        $currentPath = strtok($currentPath, '?');

        foreach ($paths as $pattern) {
            if ($this->pathMatches($currentPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a path matches a pattern (supports * wildcard).
     */
    protected function pathMatches(string $path, string $pattern): bool
    {
        $pattern = '/' . ltrim($pattern, '/');
        $path = '/' . ltrim($path, '/');

        if ($pattern === $path) {
            return true;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));
        return (bool) preg_match('#^' . $regex . '$#', $path);
    }

    /**
     * Determine if this is a preflight OPTIONS request.
     */
    protected function isPreflightRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
            && isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    }

    /**
     * Send headers for a preflight OPTIONS request.
     */
    protected function sendPreflightHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $this->sendCorsHeaders();

        $allowedMethods = $this->config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));

        $allowedHeaders = $this->config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'];
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));

        $maxAge = $this->config['max_age'] ?? 86400;
        header('Access-Control-Max-Age: ' . (int) $maxAge);
    }

    /**
     * Send standard CORS headers.
     */
    protected function sendCorsHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $allowedOrigins = $this->config['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowedOrigins, true)) {
            $supportsCredentials = $this->config['supports_credentials'] ?? false;
            if ($supportsCredentials && $origin !== '') {
                // When credentials are supported, must echo specific origin
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            } else {
                header('Access-Control-Allow-Origin: *');
            }
        } elseif ($this->isOriginAllowed($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        $supportsCredentials = $this->config['supports_credentials'] ?? false;
        if ($supportsCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        $exposedHeaders = $this->config['exposed_headers'] ?? [];
        if (!empty($exposedHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $exposedHeaders));
        }
    }

    /**
     * Check if the request origin is in the allowed list.
     */
    protected function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if ($origin === '') {
            return false;
        }

        foreach ($allowedOrigins as $allowed) {
            if ($allowed === $origin) {
                return true;
            }

            // Support wildcard subdomains: *.example.com
            if (str_contains($allowed, '*')) {
                $regex = str_replace('\*', '[a-zA-Z0-9\-]+', preg_quote($allowed, '#'));
                if (preg_match('#^' . $regex . '$#', $origin)) {
                    return true;
                }
            }
        }

        return false;
    }
}
