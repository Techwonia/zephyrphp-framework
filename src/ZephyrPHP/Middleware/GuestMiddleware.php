<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Auth\Auth;

/**
 * Guest Middleware
 *
 * Ensures the user is NOT authenticated (guest only routes).
 * Useful for login, register, and password reset pages.
 *
 * Usage in routes:
 *   Route::get('/login', [AuthController::class, 'showLogin'])
 *       ->middleware([GuestMiddleware::class]);
 */
class GuestMiddleware implements MiddlewareInterface
{
    /** @var string Redirect URL for authenticated users */
    protected string $redirectTo;

    public function __construct()
    {
        $this->redirectTo = $_ENV['AUTH_HOME'] ?? '/dashboard';
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
        if (Auth::check()) {
            // Check if it's an API request
            if ($this->isApiRequest($request)) {
                return $this->alreadyAuthenticatedResponse();
            }

            // Redirect authenticated users away from guest routes
            header('Location: ' . $this->redirectTo);
            exit;
        }

        return $next($request);
    }

    /**
     * Check if request is an API request
     */
    protected function isApiRequest($request): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/')
            || str_contains($acceptHeader, 'application/json');
    }

    /**
     * Return response for already authenticated API requests
     */
    protected function alreadyAuthenticatedResponse()
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'message' => 'Already authenticated',
            'redirect' => $this->redirectTo,
        ]);
        exit;
    }

    /**
     * Set the redirect URL
     */
    public function redirectTo(string $url): self
    {
        $this->redirectTo = $url;
        return $this;
    }
}
