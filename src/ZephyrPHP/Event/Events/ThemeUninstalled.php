<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a theme has been uninstalled (files + DB removed).
 */
class ThemeUninstalled extends Event
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
