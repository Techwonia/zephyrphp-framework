<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

/**
 * Environment Binding Builder
 *
 * Provides a fluent interface for defining environment-specific bindings.
 *
 * Usage:
 * $container->whenEnvironment('production')
 *           ->singleton(CacheInterface::class, RedisCache::class);
 *
 * $container->whenEnvironment('testing')
 *           ->singleton(CacheInterface::class, ArrayCache::class);
 */
class EnvironmentBindingBuilder
{
    private Container $container;
    private string $environment;

    public function __construct(Container $container, string $environment)
    {
        $this->container = $container;
        $this->environment = $environment;
    }

    /**
     * Register a binding for this environment
     */
    public function bind(string $abstract, mixed $concrete = null): self
    {
        $this->container->addEnvironmentBinding(
            $this->environment,
            $abstract,
            $concrete ?? $abstract,
            'bind'
        );

        return $this;
    }

    /**
     * Register a singleton for this environment
     */
    public function singleton(string $abstract, mixed $concrete = null): self
    {
        $this->container->addEnvironmentBinding(
            $this->environment,
            $abstract,
            $concrete ?? $abstract,
            'singleton'
        );

        return $this;
    }

    /**
     * Register a factory for this environment
     */
    public function factory(string $abstract, mixed $concrete = null): self
    {
        $this->container->addEnvironmentBinding(
            $this->environment,
            $abstract,
            $concrete ?? $abstract,
            'factory'
        );

        return $this;
    }

    /**
     * Register a scoped binding for this environment
     */
    public function scoped(string $abstract, mixed $concrete = null): self
    {
        $this->container->addEnvironmentBinding(
            $this->environment,
            $abstract,
            $concrete ?? $abstract,
            'scoped'
        );

        return $this;
    }
}
