<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a marketplace app has been uninstalled.
 */
class AppUninstalled extends Event
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
