<?php

declare(strict_types=1);

namespace ZephyrPHP\Event\Events;

use ZephyrPHP\Event\Event;

/**
 * Fired when a route callback has been matched,
 * before middleware and controller execution.
 */
class RouteMatched extends Event
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $callback,
        public readonly array $parameters,
    ) {
    }
}
