<?php

declare(strict_types=1);

namespace ZephyrPHP\OAuth;

use ZephyrPHP\Middleware\MiddlewareInterface;

/**
 * OAuth 2.0 API Authentication Middleware.
 *
 * Validates Bearer tokens from the Authorization header
 * against OAuth access tokens. Sets the authenticated user
 * and scopes for the request.
 *
 * Usage in routes:
 *   Route::get('/api/v1/pages', [ApiV1Controller::class, 'pages'])
 *       ->middleware([OAuthMiddleware::class]);
 *
 * Access scopes in controller:
 *   $scopes = OAuthMiddleware::$resolvedScopes;
 */
class OAuthMiddleware implements MiddlewareInterface
{
    /** @var array Resolved OAuth scopes for the current request */
    public static array $resolvedScopes = [];

    /** @var int|null Authenticated OAuth user ID */
    public static ?int $resolvedUserId = null;

    /** @var string|null Authenticated OAuth client ID */
    public static ?string $resolvedClientId = null;

    public function handle($request, callable $next)
    {
        $token = $this->extractBearerToken();

        if ($token === null) {
            return $this->unauthorized('No access token provided. Use Authorization: Bearer <token>.');
        }

        $manager = new OAuthManager();
        $payload = $manager->validateAccessToken($token);

        if ($payload === null) {
            return $this->unauthorized('Invalid or expired access token.');
        }

        // Set authenticated user
        if (class_exists(\ZephyrPHP\Auth\Auth::class)) {
            \ZephyrPHP\Auth\Auth::onceUsingId($payload['user_id']);
        }

        // Store OAuth context on static properties (avoid mutating $_REQUEST)
        self::$resolvedUserId = $payload['user_id'];
        self::$resolvedClientId = $payload['client_id'];
        self::$resolvedScopes = $payload['scopes'];

        return $next($request);
    }

    /**
     * Check if the current request has a required scope.
     */
    public static function hasScope(string $scope): bool
    {
        $scopes = self::$resolvedScopes;
        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }

    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback: X-Access-Token header
        if (!empty($_SERVER['HTTP_X_ACCESS_TOKEN'])) {
            return $_SERVER['HTTP_X_ACCESS_TOKEN'];
        }

        return null;
    }

    private function unauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer');

        echo json_encode([
            'error' => 'unauthorized',
            'error_description' => $message,
        ]);

        exit;
    }
}
