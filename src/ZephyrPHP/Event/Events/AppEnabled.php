<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a marketplace app has been enabled.
 */
class AppEnabled extends Event
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
