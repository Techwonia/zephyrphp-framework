<?php

declare(strict_types=1);

namespace ZephyrPHP\Event;

use ZephyrPHP\Container\Container;

/**
 * PSR-14 compatible Event Dispatcher.
 *
 * Dispatches events to registered listeners. Supports:
 * - Class-based event listeners (type-safe)
 * - Priority ordering (lower number = earlier execution)
 * - Listener removal
 * - Wildcard listeners (listen to all events)
 * - Container-based lazy resolution of listener classes
 *
 * Security: Listeners are validated at registration time.
 * No dynamic code execution or eval. Callables only.
 */
class EventDispatcher
{
    private static ?EventDispatcher $instance = null;

    /**
     * @var array<string, array<int, array{callable: callable, priority: int}>>
     * Listeners indexed by event class name, each containing priority-sorted callables.
     */
    private array $listeners = [];

    /**
     * @var array<int, array{callable: callable, priority: int}>
     * Wildcard listeners that receive ALL events.
     */
    private array $wildcardListeners = [];

    /**
     * @var bool Whether listeners need re-sorting after additions.
     */
    private array $needsSort = [];

    private ?Container $container = null;

    private function __construct()
    {
        // Singleton — use getInstance()
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set the container for lazy-resolving listener classes.
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Register a listener for a specific event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @param callable|string $listener Callable or "Class@method" / "Class::method" string
     * @param int $priority Lower = earlier execution (default 0)
     */
    public function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        $resolved = $this->resolveListener($listener);

        $this->listeners[$eventClass][] = [
            'callable' => $resolved,
            'priority' => $priority,
        ];

        $this->needsSort[$eventClass] = true;
    }

    /**
     * Register a wildcard listener that receives ALL dispatched events.
     *
     * @param callable|string $listener Callable or class string
     * @param int $priority Lower = earlier execution (default 0)
     */
    public function listenAll(callable|string $listener, int $priority = 0): void
    {
        $resolved = $this->resolveListener($listener);

        $this->wildcardListeners[] = [
            'callable' => $resolved,
            'priority' => $priority,
        ];

        $this->needsSort['*'] = true;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * Listeners are called in priority order (lowest first).
     * If an event's propagation is stopped, remaining listeners are skipped.
     *
     * @param Event $event The event to dispatch
     * @return Event The (possibly modified) event
     */
    public function dispatch(Event $event): Event
    {
        $eventClass = $event::class;
        $listeners = $this->getListenersForEvent($eventClass);

        foreach ($listeners as $entry) {
            if ($event->isPropagationStopped()) {
                break;
            }

            ($entry['callable'])($event);
        }

        return $event;
    }

    /**
     * Check if any listeners are registered for an event class.
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]) || !empty($this->wildcardListeners);
    }

    /**
     * Remove all listeners for a specific event class.
     */
    public function forget(string $eventClass): void
    {
        unset($this->listeners[$eventClass], $this->needsSort[$eventClass]);
    }

    /**
     * Remove ALL listeners (use with caution — mainly for testing).
     */
    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcardListeners = [];
        $this->needsSort = [];
    }

    /**
     * Get all sorted listeners for an event class, including wildcard listeners.
     *
     * @return array<int, array{callable: callable, priority: int}>
     */
    private function getListenersForEvent(string $eventClass): array
    {
        $this->sortListeners($eventClass);
        $this->sortWildcardListeners();

        $eventListeners = $this->listeners[$eventClass] ?? [];
        $wildcards = $this->wildcardListeners;

        if (empty($wildcards)) {
            return $eventListeners;
        }

        if (empty($eventListeners)) {
            return $wildcards;
        }

        // Merge and sort by priority
        $merged = array_merge($eventListeners, $wildcards);
        usort($merged, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $merged;
    }

    /**
     * Sort listeners for a specific event class by priority.
     */
    private function sortListeners(string $eventClass): void
    {
        if (!isset($this->needsSort[$eventClass]) || !$this->needsSort[$eventClass]) {
            return;
        }

        if (isset($this->listeners[$eventClass])) {
            usort(
                $this->listeners[$eventClass],
                static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']
            );
        }

        $this->needsSort[$eventClass] = false;
    }

    /**
     * Sort wildcard listeners by priority.
     */
    private function sortWildcardListeners(): void
    {
        if (!isset($this->needsSort['*']) || !$this->needsSort['*']) {
            return;
        }

        usort(
            $this->wildcardListeners,
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']
        );

        $this->needsSort['*'] = false;
    }

    /**
     * Resolve a listener definition into a callable.
     *
     * Supports:
     * - Closures and native callables (passed through)
     * - "ClassName@method" syntax (resolved via container)
     * - "ClassName::staticMethod" syntax (static call)
     * - Class name only (must be invokable — has __invoke method)
     *
     * @throws \InvalidArgumentException If listener cannot be resolved
     */
    private function resolveListener(callable|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }

        if (!is_string($listener)) {
            throw new \InvalidArgumentException('Listener must be a callable or a valid class string.');
        }

        // "ClassName@method" syntax
        if (str_contains($listener, '@')) {
            [$class, $method] = explode('@', $listener, 2);
            return $this->resolveClassMethod($class, $method);
        }

        // "ClassName::staticMethod" syntax
        if (str_contains($listener, '::')) {
            [$class, $method] = explode('::', $listener, 2);

            if (!class_exists($class)) {
                throw new \InvalidArgumentException("Listener class '{$class}' does not exist.");
            }

            if (!method_exists($class, $method)) {
                throw new \InvalidArgumentException("Static method '{$class}::{$method}' does not exist.");
            }

            return [$class, $method];
        }

        // Invokable class
        if (class_exists($listener)) {
            return $this->resolveInvokable($listener);
        }

        throw new \InvalidArgumentException("Cannot resolve listener: '{$listener}'.");
    }

    /**
     * Resolve a class@method listener, using the container if available.
     */
    private function resolveClassMethod(string $class, string $method): callable
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Listener class '{$class}' does not exist.");
        }

        if (!method_exists($class, $method)) {
            throw new \InvalidArgumentException("Method '{$class}@{$method}' does not exist.");
        }

        // Lazy resolution: return a closure that resolves at dispatch time
        return function (Event $event) use ($class, $method): void {
            $instance = $this->container !== null
                ? $this->container->make($class)
                : new $class();

            $instance->$method($event);
        };
    }

    /**
     * Resolve an invokable class (__invoke method).
     */
    private function resolveInvokable(string $class): callable
    {
        if (!method_exists($class, '__invoke')) {
            throw new \InvalidArgumentException(
                "Listener class '{$class}' must have an __invoke() method or use 'ClassName@method' syntax."
            );
        }

        return function (Event $event) use ($class): void {
            $instance = $this->container !== null
                ? $this->container->make($class)
                : new $class();

            $instance($event);
        };
    }

    /**
     * Reset the singleton instance (for testing only).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->flush();
        }
        self::$instance = null;
    }
}
