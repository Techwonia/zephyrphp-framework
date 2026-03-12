<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired before a module's service provider is registered and booted.
 */
class ModuleBooting extends Event
{
    public function __construct(
        public readonly string $moduleName,
        public readonly ?string $providerClass,
    ) {
    }
}
