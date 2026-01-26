<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Security\RateLimiter;

/**
 * Rate Limit Middleware
 *
 * Protects routes from abuse by limiting request frequency.
 *
 * Usage in routes:
 *   Route::post('/api/login', [AuthController::class, 'login'])
 *       ->middleware([new RateLimitMiddleware(5, 60)]); // 5 attempts per minute
 *
 *   // Or use the static factory
 *   Route::middleware([RateLimitMiddleware::perMinute(60)])
 *       ->prefix('/api')
 *       ->group(function() { ... });
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var int Maximum number of attempts */
    protected int $maxAttempts;

    /** @var int Decay time in seconds */
    protected int $decaySeconds;

    /** @var string Key prefix */
    protected string $prefix = 'rate_limit';

    /** @var bool Whether to use user ID in key */
    protected bool $byUser = false;

    /**
     * Create a new rate limit middleware
     *
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decaySeconds Time window in seconds
     */
    public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    /**
     * Handle the middleware
     *
     * @param mixed $request The request object
     * @param callable $next The next middleware
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        $key = $this->resolveRequestKey();

        // Check if too many attempts
        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts)) {
            return $this->tooManyAttemptsResponse($key);
        }

        // Increment the counter
        RateLimiter::hit($key, $this->decaySeconds);

        // Continue with the request
        $response = $next($request);

        // Add rate limit headers
        $this->addRateLimitHeaders($key);

        return $response;
    }

    /**
     * Resolve the rate limit key for this request
     */
    protected function resolveRequestKey(): string
    {
        $parts = [$this->prefix];

        // Add route/path
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $parts[] = md5($path);

        // Add user ID or IP
        if ($this->byUser && Auth::check()) {
            $parts[] = 'user:' . Auth::id();
        } else {
            $parts[] = 'ip:' . $this->getClientIp();
        }

        return implode(':', $parts);
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string
    {
        // Check for proxied IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR',               // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For can contain multiple IPs
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Add rate limit headers to response
     */
    protected function addRateLimitHeaders(string $key): void
    {
        $headers = RateLimiter::headers($key, $this->maxAttempts);

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    /**
     * Return too many attempts response
     */
    protected function tooManyAttemptsResponse(string $key)
    {
        $retryAfter = RateLimiter::availableIn($key);

        http_response_code(429);
        header('Content-Type: application/json');
        header("Retry-After: {$retryAfter}");

        $this->addRateLimitHeaders($key);

        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after' => $retryAfter,
        ]);

        exit;
    }

    /**
     * Set key prefix
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Rate limit by user instead of IP
     */
    public function byUser(): self
    {
        $this->byUser = true;
        return $this;
    }

    // =========================================================================
    // STATIC FACTORIES
    // =========================================================================

    /**
     * Create middleware with X requests per minute
     */
    public static function perMinute(int $maxAttempts): self
    {
        return new self($maxAttempts, 60);
    }

    /**
     * Create middleware with X requests per hour
     */
    public static function perHour(int $maxAttempts): self
    {
        return new self($maxAttempts, 3600);
    }

    /**
     * Create middleware with X requests per day
     */
    public static function perDay(int $maxAttempts): self
    {
        return new self($maxAttempts, 86400);
    }

    /**
     * Create strict rate limiting for login attempts
     */
    public static function loginAttempts(int $maxAttempts = 5): self
    {
        $middleware = new self($maxAttempts, 900); // 15 minutes
        $middleware->prefix = 'login';
        return $middleware;
    }

    /**
     * Create rate limiting for API endpoints
     */
    public static function api(int $maxAttempts = 60): self
    {
        $middleware = new self($maxAttempts, 60);
        $middleware->prefix = 'api';
        return $middleware;
    }
}
