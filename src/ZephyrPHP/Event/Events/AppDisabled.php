<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a marketplace app has been disabled.
 */
class AppDisabled extends Event
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
