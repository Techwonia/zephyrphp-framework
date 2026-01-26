<?php

declare(strict_types=1);

namespace ZephyrPHP\Module;

/**
 * Module Interface
 *
 * All optional modules must implement this interface.
 */
interface ModuleInterface
{
    /**
     * Get the module name
     */
    public static function name(): string;

    /**
     * Get module dependencies (other module names this module requires)
     *
     * @return string[]
     */
    public static function dependencies(): array;

    /**
     * Boot the module (register services, bindings, etc.)
     */
    public function boot(): void;

    /**
     * Check if the module is properly configured
     */
    public function isConfigured(): bool;

    /**
     * Get module version
     */
    public static function version(): string;
}
