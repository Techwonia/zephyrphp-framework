<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a marketplace app ZIP has been installed.
 */
class AppInstalled extends Event
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $path,
    ) {
    }
}
