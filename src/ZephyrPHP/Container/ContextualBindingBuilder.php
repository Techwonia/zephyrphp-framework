<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

/**
 * Contextual Binding Builder
 *
 * Provides a fluent interface for defining contextual bindings.
 *
 * Usage:
 * $container->when(UserController::class)
 *           ->needs(LoggerInterface::class)
 *           ->give(UserLogger::class);
 */
class ContextualBindingBuilder
{
    private Container $container;
    private string $concrete;
    private ?string $needs = null;

    public function __construct(Container $container, string $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * Define the abstract type that is needed
     */
    public function needs(string $abstract): self
    {
        $this->needs = $abstract;
        return $this;
    }

    /**
     * Define the implementation to use
     *
     * @param mixed $implementation Class name or closure
     */
    public function give(mixed $implementation): void
    {
        if ($this->needs === null) {
            throw new \LogicException('You must call needs() before give()');
        }

        $this->container->addContextualBinding(
            $this->concrete,
            $this->needs,
            $implementation
        );
    }

    /**
     * Define a tagged set of implementations
     */
    public function giveTagged(string $tag): void
    {
        $this->give(function (Container $container) use ($tag) {
            return $container->tagged($tag);
        });
    }

    /**
     * Define a configuration value
     */
    public function giveConfig(string $key, mixed $default = null): void
    {
        $this->give(function () use ($key, $default) {
            return config($key, $default);
        });
    }
}
