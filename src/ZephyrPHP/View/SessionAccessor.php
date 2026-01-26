<?php

declare(strict_types=1);

namespace ZephyrPHP\View;

use ZephyrPHP\Session\Flash;

/**
 * Session Accessor for Twig Templates
 *
 * Provides access to session and flash data in Twig templates.
 *
 * Template usage:
 *   {{ flash.success }}           - Get success message
 *   {{ flash.error }}             - Get general error message
 *   {{ flash.errors }}            - Get all validation errors
 *   {{ flash.errors.email|first }} - Get first error for email field
 *   {{ flash.old.name }}          - Get old input for name field
 *   {{ flash.hasError('email') }} - Check if email has errors
 *
 *   {{ session.user_id }}         - Get session value
 *
 * @package ZephyrPHP\View
 */
class SessionAccessor
{
    private ?FlashAccessor $flashAccessor = null;

    /**
     * Magic getter for session data
     */
    public function __get(string $name): mixed
    {
        if ($name === 'flash') {
            if ($this->flashAccessor === null) {
                $this->flashAccessor = new FlashAccessor();
            }
            return $this->flashAccessor;
        }

        // Direct session access
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        return $_SESSION[$name] ?? null;
    }

    /**
     * Magic isset for session data
     */
    public function __isset(string $name): bool
    {
        if ($name === 'flash') {
            return true;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return isset($_SESSION[$name]);
    }

    /**
     * Get a session value with optional default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if a session key exists
     */
    public function has(string $key): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return isset($_SESSION[$key]);
    }
}

/**
 * Flash Data Accessor for Twig Templates
 *
 * Provides access to flash messages in templates using the Flash class.
 *
 * @package ZephyrPHP\View
 */
class FlashAccessor
{
    private ?OldInputAccessor $oldAccessor = null;
    private ?ErrorsAccessor $errorsAccessor = null;

    /**
     * Magic getter for flash data
     */
    public function __get(string $key): mixed
    {
        return match ($key) {
            'success' => Flash::getSuccess(),
            'error' => Flash::getError(),
            'warning' => Flash::getWarning(),
            'info' => Flash::getInfo(),
            'errors' => $this->getErrorsAccessor(),
            'old', '_old_input' => $this->getOldAccessor(),
            default => Flash::get($key),
        };
    }

    /**
     * Magic isset for flash data
     */
    public function __isset(string $key): bool
    {
        return match ($key) {
            'success' => Flash::getSuccess() !== null,
            'error' => Flash::getError() !== null,
            'warning' => Flash::getWarning() !== null,
            'info' => Flash::getInfo() !== null,
            'errors' => Flash::hasErrors(),
            'old', '_old_input' => !empty(Flash::getAllOld()),
            default => Flash::has($key),
        };
    }

    /**
     * Get a flash value with optional default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->__get($key) ?? $default;
    }

    /**
     * Check if a flash key exists
     */
    public function has(string $key): bool
    {
        return $this->__isset($key);
    }

    /**
     * Check if a specific field has errors
     * Note: $field parameter is optional to support Twig's property access pattern
     * which tries hasXxx() methods when accessing properties
     */
    public function hasError(?string $field = null): bool
    {
        if ($field === null) {
            // When Twig tries hasError() for property access, return false
            // to let it fall through to __get('error')
            return false;
        }
        return Flash::hasFieldError($field);
    }

    /**
     * Get first error for a field
     */
    public function firstError(string $field): ?string
    {
        return Flash::getFirstError($field);
    }

    /**
     * Get all flash data
     */
    public function all(): array
    {
        return Flash::all();
    }

    /**
     * Get errors accessor (lazy loaded)
     */
    private function getErrorsAccessor(): ErrorsAccessor
    {
        if ($this->errorsAccessor === null) {
            $this->errorsAccessor = new ErrorsAccessor();
        }
        return $this->errorsAccessor;
    }

    /**
     * Get old input accessor (lazy loaded)
     */
    private function getOldAccessor(): OldInputAccessor
    {
        if ($this->oldAccessor === null) {
            $this->oldAccessor = new OldInputAccessor();
        }
        return $this->oldAccessor;
    }
}

/**
 * Validation Errors Accessor for Twig Templates
 *
 * Allows accessing errors like: flash.errors.email
 *
 * @package ZephyrPHP\View
 */
class ErrorsAccessor implements \IteratorAggregate, \Countable
{
    /**
     * Magic getter for field errors
     */
    public function __get(string $field): array
    {
        return Flash::getFieldErrors($field);
    }

    /**
     * Magic isset for field errors
     */
    public function __isset(string $field): bool
    {
        return Flash::hasFieldError($field);
    }

    /**
     * Get errors for a field
     */
    public function get(string $field): array
    {
        return Flash::getFieldErrors($field);
    }

    /**
     * Get first error for a field
     */
    public function first(string $field): ?string
    {
        return Flash::getFirstError($field);
    }

    /**
     * Check if field has errors
     */
    public function has(string $field): bool
    {
        return Flash::hasFieldError($field);
    }

    /**
     * Check if any errors exist
     */
    public function any(): bool
    {
        return Flash::hasErrors();
    }

    /**
     * Get all errors
     */
    public function all(): array
    {
        return Flash::getErrors();
    }

    /**
     * Get count of fields with errors
     */
    public function count(): int
    {
        return count(Flash::getErrors());
    }

    /**
     * Allow iteration over errors in templates
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(Flash::getErrors());
    }
}

/**
 * Old Input Accessor for Twig Templates
 *
 * Allows accessing old input like: flash.old.name
 *
 * @package ZephyrPHP\View
 */
class OldInputAccessor implements \IteratorAggregate
{
    /**
     * Magic getter for old input
     */
    public function __get(string $key): mixed
    {
        return Flash::getOld($key);
    }

    /**
     * Magic isset for old input
     */
    public function __isset(string $key): bool
    {
        return Flash::getOld($key) !== null;
    }

    /**
     * Get old input value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Flash::getOld($key, $default);
    }

    /**
     * Check if old input exists
     */
    public function has(string $key): bool
    {
        return Flash::getOld($key) !== null;
    }

    /**
     * Get all old input
     */
    public function all(): array
    {
        return Flash::getAllOld();
    }

    /**
     * Allow iteration over old input in templates
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(Flash::getAllOld());
    }
}
