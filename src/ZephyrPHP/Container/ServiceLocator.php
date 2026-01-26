<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

use Psr\Container\ContainerInterface;

/**
 * Service Locator
 *
 * A lightweight container that provides access to a limited set of services.
 * Useful for injecting into controllers or commands that need multiple services
 * but don't want constructor explosion.
 *
 * Usage:
 * $locator = $container->createServiceLocator([
 *     LoggerInterface::class,
 *     CacheInterface::class,
 *     'mailer',
 * ]);
 *
 * $logger = $locator->get(LoggerInterface::class);
 */
class ServiceLocator implements ContainerInterface
{
    private Container $container;
    private array $services;

    public function __construct(Container $container, array $services)
    {
        $this->container = $container;
        $this->services = array_flip($services);
    }

    /**
     * Get a service from the locator (PSR-11)
     *
     * @throws NotFoundException if service is not in the allowed list
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw NotFoundException::notFound($id);
        }

        return $this->container->get($id);
    }

    /**
     * Check if a service is available in the locator (PSR-11)
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * Get all available service IDs
     */
    public function getProvidedServices(): array
    {
        return array_keys($this->services);
    }
}
