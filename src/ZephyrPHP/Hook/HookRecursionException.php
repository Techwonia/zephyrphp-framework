<?php

declare(strict_types=1);

namespace ZephyrPHP\Hook;

/**
 * Thrown when a hook exceeds its maximum recursion depth.
 *
 * This typically indicates an infinite loop where a hook's
 * callback triggers the same hook again indefinitely.
 */
class HookRecursionException extends \RuntimeException
{
}
