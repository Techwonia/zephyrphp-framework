<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired at the very start of the application bootstrap,
 * before modules are loaded.
 */
class AppBooting extends Event
{
}
