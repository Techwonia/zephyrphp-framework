<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

use ZephyrPHP\Event\Event;
use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Hook\HookManager;

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

    // ========================================================================
    // EVENT HELPERS — convenience methods for service providers
    // ========================================================================

    /**
     * Register a listener for an event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @param callable|string $listener Callable or "Class@method" string
     * @param int $priority Lower = earlier (default 0)
     */
    protected function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        EventDispatcher::getInstance()->listen($eventClass, $listener, $priority);
    }

    /**
     * Dispatch an event.
     *
     * @param Event $event The event instance to dispatch
     * @return Event The dispatched event
     */
    protected function dispatch(Event $event): Event
    {
        return EventDispatcher::getInstance()->dispatch($event);
    }

    // ========================================================================
    // HOOK HELPERS — convenience methods for service providers
    // ========================================================================

    /**
     * Register an action hook callback.
     *
     * @param string $hook Hook name (e.g., 'page.saved')
     * @param callable $callback The callback to execute
     * @param int $priority Lower = earlier (default 10)
     */
    protected function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        HookManager::getInstance()->addAction($hook, $callback, $priority);
    }

    /**
     * Register a filter hook callback.
     *
     * @param string $hook Hook name (e.g., 'page.content')
     * @param callable $callback Receives value, must return modified value
     * @param int $priority Lower = earlier (default 10)
     */
    protected function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        HookManager::getInstance()->addFilter($hook, $callback, $priority);
    }
}
