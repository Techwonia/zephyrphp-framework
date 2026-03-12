<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after a module's service provider has been registered and booted.
 */
class ModuleBooted extends Event
{
    public function __construct(
        public readonly string $moduleName,
        public readonly object $provider,
    ) {
    }
}
