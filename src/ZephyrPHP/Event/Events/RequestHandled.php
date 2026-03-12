<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired after the router has dispatched the request
 * and a response has been sent.
 */
class RequestHandled extends Event
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly int $statusCode,
    ) {
    }
}
