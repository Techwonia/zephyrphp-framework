<?php

declare(strict_types=1);

namespace ZephyrPHP\Module;

use ZephyrPHP\Container\Container;

/**
 * Base Module
 *
 * Abstract base class for all modules providing common functionality.
 */
abstract class BaseModule implements ModuleInterface
{
    protected Container $container;

    public function __construct()
    {
        $this->container = Container::getInstance();
    }

    /**
     * {@inheritdoc}
     */
    public static function dependencies(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * {@inheritdoc}
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Register a singleton in the container
     */
    protected function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->container->singleton($abstract, $concrete);
    }

    /**
     * Register an instance in the container
     */
    protected function instance(string $abstract, mixed $instance): void
    {
        $this->container->instance($abstract, $instance);
    }

    /**
     * Register a binding in the container
     */
    protected function bind(string $abstract, mixed $concrete = null): void
    {
        $this->container->bind($abstract, $concrete);
    }
}
