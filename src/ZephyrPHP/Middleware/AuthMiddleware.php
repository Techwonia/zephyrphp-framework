<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Auth\Auth;

/**
 * Authentication Middleware
 *
 * Ensures the user is authenticated before accessing protected routes.
 *
 * Usage in routes:
 *   Route::get('/dashboard', [DashboardController::class, 'index'])
 *       ->middleware([AuthMiddleware::class]);
 *
 *   // Or with route groups
 *   Route::middleware([AuthMiddleware::class])->group(function() {
 *       Route::get('/profile', ...);
 *       Route::get('/settings', ...);
 *   });
 */
class AuthMiddleware implements MiddlewareInterface
{
    /** @var string Redirect URL for unauthenticated users */
    protected string $redirectTo = '/login';

    /**
     * Handle the middleware
     *
     * @param mixed $request The request object
     * @param callable $next The next middleware
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        if (Auth::guest()) {
            // Check if it's an API request
            if ($this->isApiRequest($request)) {
                return $this->unauthenticatedResponse();
            }

            // Redirect to login for web requests
            return $this->redirectToLogin($request);
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
     * Return unauthorized response for API requests
     */
    protected function unauthenticatedResponse()
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthenticated',
            'message' => 'You must be logged in to access this resource.',
        ]);
        exit;
    }

    /**
     * Redirect to login page
     */
    protected function redirectToLogin($request)
    {
        // Store intended URL for redirect after login (sanitized)
        $intendedUrl = $_SERVER['REQUEST_URI'] ?? '/';

        // Validate: must start with a single '/' and must not start with '//' (open redirect)
        if (!str_starts_with($intendedUrl, '/') || str_starts_with($intendedUrl, '//')) {
            $intendedUrl = '/';
        }

        $session = \ZephyrPHP\Session\Session::getInstance();
        $session->set('url_intended', $intendedUrl);

        header('Location: ' . $this->redirectTo);
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
