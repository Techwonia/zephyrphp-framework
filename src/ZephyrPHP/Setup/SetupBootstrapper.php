<?php

declare(strict_types=1);

namespace ZephyrPHP\Setup;

use ZephyrPHP\Router\Route;
use ZephyrPHP\View\View;

/**
 * Conditionally wires the first-run setup wizard.
 *
 * When the host project has no `storage/.installed` marker, this registers
 * the `@setup` Twig namespace and the `/setup` routes. Once installation
 * completes and the marker is written, this class becomes a no-op and adds
 * zero overhead to subsequent requests.
 *
 * Invoked automatically by Application::bootstrap() before user routes load.
 */
class SetupBootstrapper
{
    public static function register(): void
    {
        if (!defined('BASE_PATH')) {
            return;
        }

        if (file_exists(BASE_PATH . '/storage/.installed')) {
            return;
        }

        // Force any non-setup, non-asset request to the wizard so first-run
        // users land on /setup regardless of the URL they hit. Static assets
        // (CSS/JS/images/fonts) pass through so the wizard can render.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (!str_starts_with($path, '/setup') && !self::isStaticAssetPath($path)) {
            header('Location: /setup');
            exit;
        }

        // Register Twig namespace @setup → framework's shipped wizard view
        try {
            View::getInstance()->addNamespace('setup', __DIR__ . '/views');
        } catch (\Throwable $e) {
            // View engine not yet available; skip gracefully
        }

        Route::get('/setup', [SetupController::class, 'index']);
        Route::post('/setup/save-settings', [SetupController::class, 'saveSettings']);
        Route::post('/setup/setup-database', [SetupController::class, 'setupDatabase']);
        Route::post('/setup/create-admin', [SetupController::class, 'createAdmin']);
    }

    private static function isStaticAssetPath(string $path): bool
    {
        return (bool) preg_match(
            '#\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|eot)$#i',
            $path
        );
    }
}
