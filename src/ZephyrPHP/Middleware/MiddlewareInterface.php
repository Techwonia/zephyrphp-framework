<?php

declare(strict_types=1);

namespace ZephyrPHP\Middleware;

/**
 * Middleware Interface
 *
 * All middleware must implement this interface.
 */
interface MiddlewareInterface
{
    /**
     * Handle the middleware
     *
     * @param mixed $request The request object
     * @param callable $next The next middleware in the pipeline
     * @return mixed The response
     */
    public function handle($request, callable $next);
}
