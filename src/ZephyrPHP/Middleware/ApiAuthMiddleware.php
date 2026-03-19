<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Auth\JwtToken;

/**
 * API Authentication Middleware
 *
 * Authenticates API requests using JWT Bearer tokens.
 *
 * Usage in routes:
 *   Route::get('/api/user', [ApiController::class, 'user'])
 *       ->middleware([ApiAuthMiddleware::class]);
 *
 * Client usage:
 *   Authorization: Bearer <token>
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    /**
     * Handle the middleware
     *
     * @param mixed $request The request object
     * @param callable $next The next middleware
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        $token = $this->getTokenFromRequest();

        if ($token === null) {
            return $this->unauthorized('No token provided');
        }

        // Validate the JWT token
        $payload = JwtToken::validate($token);

        if ($payload === null) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Get user from token
        $userId = $payload['sub'] ?? $payload['user_id'] ?? null;

        if ($userId === null) {
            return $this->unauthorized('Token missing user identifier');
        }

        // Authenticate the user for this request
        if (!Auth::onceUsingId($userId)) {
            return $this->unauthorized('User not found');
        }

        return $next($request);
    }

    /**
     * Get the token from the request
     */
    protected function getTokenFromRequest(): ?string
    {
        // Check Authorization header (Bearer token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Fallback: Check X-Token header
        if (!empty($_SERVER['HTTP_X_TOKEN'])) {
            return $_SERVER['HTTP_X_TOKEN'];
        }

        // Do NOT accept tokens from query parameters ($_GET) as they are logged
        // in server access logs, browser history, and referer headers.

        return null;
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorized(string $message)
    {
        http_response_code(401);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer');

        echo json_encode([
            'error' => 'Unauthorized',
            'message' => $message,
        ]);

        exit;
    }
}
