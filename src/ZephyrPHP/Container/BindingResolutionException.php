<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

use Psr\Container\ContainerExceptionInterface;
use Exception;

/**
 * Exception thrown when a binding cannot be resolved
 */
class BindingResolutionException extends Exception implements ContainerExceptionInterface
{
    /**
     * Create exception for unresolvable binding
     */
    public static function unresolvable(string $abstract, ?string $message = null): self
    {
        $msg = "Target [{$abstract}] is not instantiable";
        if ($message) {
            $msg .= ": {$message}";
        }
        return new self($msg);
    }

    /**
     * Create exception for circular dependency
     */
    public static function circularDependency(string $abstract, array $buildStack): self
    {
        $chain = implode(' -> ', $buildStack) . ' -> ' . $abstract;
        return new self("Circular dependency detected while resolving [{$abstract}]: {$chain}");
    }

    /**
     * Create exception for missing parameter
     */
    public static function missingParameter(string $abstract, string $parameter): self
    {
        return new self("Unable to resolve dependency [{$parameter}] in class [{$abstract}]");
    }
}
