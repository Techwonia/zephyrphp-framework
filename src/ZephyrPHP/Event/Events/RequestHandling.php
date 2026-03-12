<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired before the router dispatches the current request.
 */
class RequestHandling extends Event
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }
}
