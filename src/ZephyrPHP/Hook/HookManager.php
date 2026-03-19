<?php

declare(strict_types=1);

namespace ZephyrPHP\Hook;

/**
 * Hook Manager — WordPress-style Actions & Filters.
 *
 * Actions: Fire-and-forget notifications. Multiple listeners can
 *          react to an action, but they don't return values.
 *
 * Filters: Transform data through a pipeline of callbacks.
 *          Each callback receives the current value and returns
 *          the (possibly modified) value for the next callback.
 *
 * Security:
 * - Hook names are validated (alphanumeric, dots, underscores, colons only)
 * - Callbacks are validated as callable at registration time
 * - No dynamic eval or code generation
 * - Recursive hook depth is limited to prevent infinite loops
 */
class HookManager
{
    private static ?HookManager $instance = null;

    /**
     * Maximum recursion depth for hooks to prevent infinite loops.
     */
    private const MAX_DEPTH = 10;

    /**
     * @var array<string, array<int, array{callback: callable, priority: int}>>
     * Registered action hooks.
     */
    private array $actions = [];

    /**
     * @var array<string, array<int, array{callback: callable, priority: int}>>
     * Registered filter hooks.
     */
    private array $filters = [];

    /**
     * @var array<string, bool> Tracks whether hooks need re-sorting.
     */
    private array $needsSort = [];

    /**
     * @var array<string, int> Tracks current recursion depth per hook.
     */
    private array $currentDepth = [];

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

    // ========================================================================
    // ACTIONS (fire-and-forget)
    // ========================================================================

    /**
     * Register a callback for an action hook.
     *
     * @param string $hook Hook name (e.g., 'page.saved', 'theme.activated')
     * @param callable $callback The callback to execute
     * @param int $priority Lower = earlier execution (default 10)
     * @throws \InvalidArgumentException If hook name is invalid
     */
    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->validateHookName($hook);

        $this->actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];

        $this->needsSort["action:{$hook}"] = true;
    }

    /**
     * Execute all callbacks registered for an action hook.
     *
     * @param string $hook Hook name
     * @param mixed ...$args Arguments to pass to callbacks
     * @throws HookRecursionException If recursion depth exceeds MAX_DEPTH
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        $this->guardRecursion($hook);

        $this->currentDepth[$hook] = ($this->currentDepth[$hook] ?? 0) + 1;

        try {
            if (empty($this->actions[$hook])) {
                return;
            }

            $this->sortHook("action:{$hook}", $this->actions[$hook]);

            foreach ($this->actions[$hook] as $entry) {
                ($entry['callback'])(...$args);
            }
        } finally {
            $this->currentDepth[$hook]--;

            if ($this->currentDepth[$hook] <= 0) {
                unset($this->currentDepth[$hook]);
            }
        }
    }

    /**
     * Check if any callbacks are registered for an action hook.
     */
    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    /**
     * Remove all callbacks for a specific action hook.
     */
    public function removeAction(string $hook): void
    {
        unset($this->actions[$hook], $this->needsSort["action:{$hook}"]);
    }

    // ========================================================================
    // FILTERS (transform data through pipeline)
    // ========================================================================

    /**
     * Register a callback for a filter hook.
     *
     * @param string $hook Hook name (e.g., 'page.content', 'asset.url')
     * @param callable $callback Receives value as first arg, must return modified value
     * @param int $priority Lower = earlier execution (default 10)
     * @throws \InvalidArgumentException If hook name is invalid
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->validateHookName($hook);

        $this->filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];

        $this->needsSort["filter:{$hook}"] = true;
    }

    /**
     * Apply all filter callbacks to a value and return the result.
     *
     * Each callback receives the current value (and any extra args)
     * and must return the (possibly modified) value.
     *
     * @param string $hook Hook name
     * @param mixed $value The value to filter
     * @param mixed ...$args Additional arguments for callbacks
     * @return mixed The filtered value
     * @throws HookRecursionException If recursion depth exceeds MAX_DEPTH
     */
    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $this->guardRecursion($hook);

        $this->currentDepth[$hook] = ($this->currentDepth[$hook] ?? 0) + 1;

        try {
            if (empty($this->filters[$hook])) {
                return $value;
            }

            $this->sortHook("filter:{$hook}", $this->filters[$hook]);

            foreach ($this->filters[$hook] as $entry) {
                $value = ($entry['callback'])($value, ...$args);
            }

            return $value;
        } finally {
            $this->currentDepth[$hook]--;

            if ($this->currentDepth[$hook] <= 0) {
                unset($this->currentDepth[$hook]);
            }
        }
    }

    /**
     * Check if any callbacks are registered for a filter hook.
     */
    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /**
     * Remove all callbacks for a specific filter hook.
     */
    public function removeFilter(string $hook): void
    {
        unset($this->filters[$hook], $this->needsSort["filter:{$hook}"]);
    }

    // ========================================================================
    // INTERNAL
    // ========================================================================

    /**
     * Validate hook name format.
     * Only allows: letters, numbers, dots, underscores, colons, hyphens.
     *
     * @throws \InvalidArgumentException
     */
    private function validateHookName(string $hook): void
    {
        if ($hook === '' || !preg_match('/^[a-zA-Z0-9._:\-]+$/', $hook)) {
            throw new \InvalidArgumentException(
                "Invalid hook name '{$hook}'. Only letters, numbers, dots, underscores, colons, and hyphens are allowed."
            );
        }

        if (strlen($hook) > 128) {
            throw new \InvalidArgumentException(
                "Hook name '{$hook}' exceeds maximum length of 128 characters."
            );
        }
    }

    /**
     * Guard against infinite recursion in hooks.
     *
     * @throws HookRecursionException
     */
    private function guardRecursion(string $hook): void
    {
        $depth = $this->currentDepth[$hook] ?? 0;

        if ($depth >= self::MAX_DEPTH) {
            throw new HookRecursionException(
                "Hook '{$hook}' exceeded maximum recursion depth of " . self::MAX_DEPTH . '. ' .
                'This usually indicates an infinite loop where a hook triggers itself.'
            );
        }
    }

    /**
     * Sort a hook's callbacks by priority if needed.
     */
    private function sortHook(string $key, array &$entries): void
    {
        if (!isset($this->needsSort[$key]) || !$this->needsSort[$key]) {
            return;
        }

        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $this->needsSort[$key] = false;
    }

    /**
     * Remove ALL hooks (actions and filters). Mainly for testing.
     */
    public function flush(): void
    {
        $this->actions = [];
        $this->filters = [];
        $this->needsSort = [];
        $this->currentDepth = [];
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
