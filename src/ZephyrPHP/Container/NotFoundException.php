<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

/**
 * Exception thrown when a requested service is not found in the container
 */
class NotFoundException extends Exception implements NotFoundExceptionInterface
{
    /**
     * Create exception for not found service
     */
    public static function notFound(string $id): self
    {
        return new self("No entry was found for [{$id}] in the container.");
    }

    /**
     * Create exception for unregistered alias
     */
    public static function aliasNotFound(string $alias): self
    {
        return new self("Alias [{$alias}] is not registered in the container.");
    }

    /**
     * Create exception for missing service provider
     */
    public static function providerNotFound(string $provider): self
    {
        return new self("Service provider [{$provider}] was not found.");
    }
}
