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
}
