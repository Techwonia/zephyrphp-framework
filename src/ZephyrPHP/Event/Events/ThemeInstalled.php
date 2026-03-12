<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a theme ZIP has been installed.
 */
class ThemeInstalled extends Event
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $path,
    ) {
    }
}
