<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired before a theme is activated (set to live).
 * Listeners can stop propagation to prevent activation.
 */
class ThemeActivating extends Event
{
    public function __construct(
        public readonly string $slug,
        public readonly ?string $previousSlug,
    ) {
    }
}
