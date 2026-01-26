<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

/**
 * Base Service Provider
 *
 * Service providers are the central place to configure your application.
 * They bootstrap services, register bindings, and configure the container.
 */
abstract class ServiceProvider
{
    /**
     * Register bindings in the container.
     * This is called before the application boots.
     */
    abstract public function register(Container $container): void;

    /**
     * Bootstrap any application services.
     * This is called after all providers have been registered.
     */
    public function boot(Container $container): void
    {
        // Override in child class if needed
    }

    /**
     * Get the services provided by the provider.
     * Used for deferred loading.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [];
    }
}
