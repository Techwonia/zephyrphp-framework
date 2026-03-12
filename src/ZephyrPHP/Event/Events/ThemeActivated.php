<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a theme has been activated (set to live).
 * Assets have been published at this point.
 */
class ThemeActivated extends Event
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
