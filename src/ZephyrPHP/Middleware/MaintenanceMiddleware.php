<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

/**
 * Maintenance mode middleware.
 *
 * Checks for a storage/framework/down file. If present, returns a 503
 * maintenance page unless the request IP is in the allowed list or a
 * bypass secret cookie is set.
 *
 * The down file is JSON:
 *   {"message": "We'll be right back.", "retry": 60, "allowed": ["127.0.0.1"], "secret": "bypass-token"}
 */
class MaintenanceMiddleware implements MiddlewareInterface
{
    public function handle($request, callable $next)
    {
        $downFile = $this->getDownFilePath();

        if (!file_exists($downFile)) {
            return $next($request);
        }

        $data = $this->readDownFile($downFile);

        // The secret is stored as a bcrypt hash in the down file.
        $secretHash = $data['secret'] ?? null;

        // Check bypass secret via cookie (cookie holds an HMAC of the plaintext)
        if ($secretHash !== null && isset($_COOKIE['maintenance_bypass'])) {
            if (hash_equals($secretHash, $_COOKIE['maintenance_bypass'])) {
                return $next($request);
            }
        }

        // Check bypass secret via URL query parameter — verify against bcrypt hash
        if ($secretHash !== null && isset($_GET['bypass']) && password_verify($_GET['bypass'], $secretHash)) {
            // Store the hash itself in the cookie for subsequent requests
            setcookie('maintenance_bypass', $secretHash, [
                'expires' => time() + 86400 * 30,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl, true, 302);
            }
            return null;
        }

        // Check allowed IPs
        $allowed = $data['allowed'] ?? [];
        $clientIp = $this->getClientIp();
        if (!empty($allowed) && in_array($clientIp, $allowed, true)) {
            return $next($request);
        }

        // Show maintenance page
        $this->renderMaintenancePage($data);
        return null;
    }

    /**
     * Get the path to the down file.
     */
    protected function getDownFilePath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/storage/framework/down';
    }

    /**
     * Read and parse the down file.
     */
    protected function readDownFile(string $path): array
    {
        $content = file_get_contents($path);
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Get the client IP address.
     *
     * Uses REMOTE_ADDR directly to avoid IP spoofing via proxy headers.
     * If the framework Request class is available, delegates to its
     * trusted-proxy-aware ip() method instead.
     */
    protected function getClientIp(): string
    {
        // Prefer the framework Request which respects trusted proxy configuration
        if (class_exists(\ZephyrPHP\Core\Http\Request::class)) {
            return \ZephyrPHP\Core\Http\Request::getInstance()->ip();
        }

        // Fallback: use REMOTE_ADDR only (not spoofable)
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Render the maintenance mode page.
     */
    protected function renderMaintenancePage(array $data): void
    {
        $message = htmlspecialchars($data['message'] ?? 'We are currently performing maintenance. Please check back soon.', ENT_QUOTES, 'UTF-8');
        $retry = isset($data['retry']) ? (int) $data['retry'] : null;

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            if ($retry !== null) {
                header('Retry-After: ' . $retry);
            }
        }

        // Try user-defined maintenance template
        if ($this->renderCustomTemplate($data)) {
            return;
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>503 - Maintenance</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f8fafc; color: #1e293b; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { text-align: center; padding: 48px 24px; max-width: 520px; }
        .icon { font-size: 4rem; margin-bottom: 24px; }
        h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 12px; color: #0f172a; }
        p { font-size: 1.1rem; color: #64748b; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">&#9881;</div>
        <h1>Under Maintenance</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Try to render a custom maintenance template.
     */
    protected function renderCustomTemplate(array $data): bool
    {
        try {
            $viewsPath = $_ENV['VIEWS_PATH'] ?? 'pages';
            $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
            $templateFile = $basePath . '/' . ltrim($viewsPath, '/') . '/errors/503.twig';

            if (!file_exists($templateFile)) {
                return false;
            }

            if (function_exists('view')) {
                echo view('errors/503', [
                    'code' => 503,
                    'title' => 'Under Maintenance',
                    'message' => $data['message'] ?? 'We are currently performing maintenance.',
                ]);
                return true;
            }
        } catch (\Throwable $e) {
            // Ignore template errors
        }

        return false;
    }
}
