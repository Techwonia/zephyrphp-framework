<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after the application has fully booted:
 * modules loaded, routes registered, assets configured.
 */
class AppBooted extends Event
{
}
