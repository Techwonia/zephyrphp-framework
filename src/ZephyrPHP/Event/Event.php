<?php

declare(strict_types=1);

namespace ZephyrPHP\Event;

/**
 * Base Event class.
 *
 * All framework events extend this class. Events carry immutable
 * context about something that happened in the application.
 *
 * Listeners can stop propagation to prevent subsequent listeners
 * from being called.
 */
abstract class Event
{
    private bool $propagationStopped = false;

    /**
     * Stop further listeners from being called for this event.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * Whether propagation has been stopped by a listener.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Get the event name (fully qualified class name by default).
     */
    public function getName(): string
    {
        return static::class;
    }
}
