<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired when the application is shutting down,
 * before session write-close and cleanup.
 */
class AppTerminating extends Event
{
}
