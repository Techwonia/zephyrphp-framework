<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

use ZephyrPHP\Authorization\Gate;
use ZephyrPHP\Authorization\AuthorizationException;

/**
 * Authorization Middleware
 *
 * Checks if user has a specific ability/permission.
 *
 * Usage in routes:
 *   Route::get('/admin', [AdminController::class, 'index'])
 *       ->middleware([new CanMiddleware('access-admin')]);
 *
 *   Route::put('/posts/{id}', [PostController::class, 'update'])
 *       ->middleware([new CanMiddleware('update', 'post')]);
 */
class CanMiddleware implements MiddlewareInterface
{
    /** @var string The ability to check */
    protected string $ability;

    /** @var string|null The model type (for policy checks) */
    protected ?string $model;

    /** @var string|null The route parameter for model ID */
    protected ?string $modelParam;

    /**
     * Create a new authorization middleware
     *
     * @param string $ability The ability to check
     * @param string|null $model The model class (optional)
     * @param string|null $modelParam Route parameter name for model ID
     */
    public function __construct(string $ability, ?string $model = null, ?string $modelParam = null)
    {
        $this->ability = $ability;
        $this->model = $model;
        $this->modelParam = $modelParam;
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
        $arguments = $this->getArguments($request);

        try {
            Gate::authorize($this->ability, ...$arguments);
        } catch (AuthorizationException $e) {
            return $this->handleUnauthorized($e);
        }

        return $next($request);
    }

    /**
     * Get arguments for the authorization check
     */
    protected function getArguments($request): array
    {
        if ($this->model === null) {
            return [];
        }

        // Try to resolve the model instance
        $modelId = $this->getModelId($request);

        if ($modelId !== null && class_exists($this->model)) {
            $model = $this->model;

            // Try to find the model
            if (method_exists($model, 'find')) {
                $instance = $model::find($modelId);
                if ($instance !== null) {
                    return [$instance];
                }
            }
        }

        // Return model class for "create" type permissions
        return [$this->model];
    }

    /**
     * Get model ID from request
     */
    protected function getModelId($request): ?string
    {
        // Check route parameters (this depends on router implementation)
        // For now, check common parameter names
        $paramNames = [
            $this->modelParam,
            'id',
            strtolower(class_basename($this->model ?? '')) . '_id',
        ];

        foreach (array_filter($paramNames) as $param) {
            // Check GET parameters — validate as integer to prevent injection
            if (!empty($_GET[$param])) {
                $id = $_GET[$param];
                if (!ctype_digit((string) $id)) {
                    return null;
                }
                return $id;
            }

            // Check route params if available in request
            if (is_object($request) && method_exists($request, 'route')) {
                $value = $request->route($param);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Handle unauthorized access
     */
    protected function handleUnauthorized(AuthorizationException $e)
    {
        $statusCode = $e->getStatusCode();

        // Check if it's an API request
        if ($this->isApiRequest()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => $statusCode === 404 ? 'Not Found' : 'Forbidden',
                'message' => $e->getMessage(),
            ]);
            exit;
        }

        // Web request - show error page or redirect
        http_response_code($statusCode);
        echo $this->getErrorPage($statusCode, $e->getMessage());
        exit;
    }

    /**
     * Check if request is an API request
     */
    protected function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/')
            || str_contains($acceptHeader, 'application/json');
    }

    /**
     * Get error page HTML
     */
    protected function getErrorPage(int $code, string $message): string
    {
        $title = $code === 404 ? 'Not Found' : 'Forbidden';
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$code} {$safeTitle}</title>
    <style>
        body { font-family: system-ui, sans-serif; text-align: center; padding: 50px; }
        h1 { font-size: 4rem; margin: 0; color: #e53e3e; }
        p { color: #718096; font-size: 1.25rem; }
    </style>
</head>
<body>
    <h1>{$code}</h1>
    <p>{$safeMessage}</p>
</body>
</html>
HTML;
    }

    // =========================================================================
    // STATIC FACTORIES
    // =========================================================================

    /**
     * Check if user can perform ability
     */
    public static function can(string $ability, ?string $model = null): self
    {
        return new self($ability, $model);
    }

    /**
     * Check if user has a role
     */
    public static function role(string $role): self
    {
        return new self("role:{$role}");
    }

    /**
     * Check if user has a permission
     */
    public static function permission(string $permission): self
    {
        return new self("permission:{$permission}");
    }
}

/**
 * Get class basename (if not defined)
 */
if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
