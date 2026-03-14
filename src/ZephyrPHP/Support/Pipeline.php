<?php

declare(strict_types=1);

namespace ZephyrPHP\Support;

/**
 * Pipeline for chaining operations on a value.
 *
 * Usage:
 *   $result = Pipeline::send($request)
 *       ->through([TrimStrings::class, ValidateCsrf::class])
 *       ->then(fn($request) => $controller->handle($request));
 *
 *   // Or with closures:
 *   $result = Pipeline::send($data)
 *       ->pipe(fn($data, $next) => $next(strtolower($data)))
 *       ->pipe(fn($data, $next) => $next(trim($data)))
 *       ->thenReturn();
 */
class Pipeline
{
    protected mixed $passable;
    protected array $pipes = [];
    protected string $method = 'handle';

    public function __construct(mixed $passable = null)
    {
        $this->passable = $passable;
    }

    /**
     * Create a new pipeline with the given object.
     */
    public static function send(mixed $passable): self
    {
        return new self($passable);
    }

    /**
     * Set the array of pipes (classes or callables).
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * Add a single pipe to the pipeline.
     */
    public function pipe(callable|string $pipe): self
    {
        $this->pipes[] = $pipe;
        return $this;
    }

    /**
     * Set the method to call on pipe classes.
     */
    public function via(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     */
    public function then(callable $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            function ($passable) use ($destination) {
                return $destination($passable);
            }
        );

        return $pipeline($this->passable);
    }

    /**
     * Run the pipeline and return the result.
     */
    public function thenReturn(): mixed
    {
        return $this->then(fn($passable) => $passable);
    }

    /**
     * Get a closure that wraps each pipe around the next.
     */
    protected function carry(): \Closure
    {
        return function ($next, $pipe) {
            return function ($passable) use ($next, $pipe) {
                if (is_callable($pipe)) {
                    return $pipe($passable, $next);
                }

                if (is_string($pipe) && class_exists($pipe)) {
                    $instance = new $pipe();
                    return $instance->{$this->method}($passable, $next);
                }

                return $next($passable);
            };
        };
    }
}
