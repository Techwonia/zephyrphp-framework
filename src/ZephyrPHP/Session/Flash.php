<?php

declare(strict_types=1);

namespace ZephyrPHP\Session;

/**
 * Flash Message Manager
 *
 * Handles flash messages that persist for exactly one request.
 * Following Laravel/Symfony conventions for flash message management.
 *
 * Usage:
 *   // Setting flash messages
 *   Flash::set('success', 'Record created successfully!');
 *   Flash::set('error', 'Something went wrong.');
 *   Flash::errors(['email' => ['Email is already taken.']]);
 *   Flash::old(['name' => 'John', 'email' => 'john@example.com']);
 *
 *   // Getting flash messages (in templates or controllers)
 *   Flash::get('success');
 *   Flash::get('error');
 *   Flash::getErrors();
 *   Flash::getOld('name');
 *
 * @package ZephyrPHP\Session
 */
class Flash
{
    /**
     * Session key for flash data
     */
    private const FLASH_KEY = '_flash';

    /**
     * Session key for flash data that will be available on next request
     */
    private const FLASH_NEW = '_flash_new';

    /**
     * Set a flash message
     */
    public static function set(string $key, mixed $value): void
    {
        self::ensureSession();
        $_SESSION[self::FLASH_NEW][$key] = $value;
    }

    /**
     * Get a flash message
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureSession();
        return $_SESSION[self::FLASH_KEY][$key] ?? $default;
    }

    /**
     * Check if a flash message exists
     */
    public static function has(string $key): bool
    {
        self::ensureSession();
        return isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /**
     * Set validation errors
     *
     * @param array<string, array<string>> $errors ['field' => ['error message', ...], ...]
     */
    public static function errors(array $errors): void
    {
        self::set('errors', $errors);
    }

    /**
     * Get all validation errors
     *
     * @return array<string, array<string>>
     */
    public static function getErrors(): array
    {
        return self::get('errors', []);
    }

    /**
     * Get errors for a specific field
     *
     * @return array<string>
     */
    public static function getFieldErrors(string $field): array
    {
        $errors = self::getErrors();
        return $errors[$field] ?? [];
    }

    /**
     * Get first error for a specific field
     */
    public static function getFirstError(string $field): ?string
    {
        $errors = self::getFieldErrors($field);
        return $errors[0] ?? null;
    }

    /**
     * Check if there are any errors
     */
    public static function hasErrors(): bool
    {
        return !empty(self::getErrors());
    }

    /**
     * Check if a specific field has errors
     */
    public static function hasFieldError(string $field): bool
    {
        return !empty(self::getFieldErrors($field));
    }

    /**
     * Set old input data (for repopulating forms)
     *
     * @param array<string, mixed> $input
     */
    public static function old(array $input): void
    {
        self::set('_old_input', $input);
    }

    /**
     * Get old input value
     */
    public static function getOld(string $key, mixed $default = null): mixed
    {
        $oldInput = self::get('_old_input', []);
        return $oldInput[$key] ?? $default;
    }

    /**
     * Get all old input
     *
     * @return array<string, mixed>
     */
    public static function getAllOld(): array
    {
        return self::get('_old_input', []);
    }

    /**
     * Set a success message
     */
    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    /**
     * Get success message
     */
    public static function getSuccess(): ?string
    {
        return self::get('success');
    }

    /**
     * Set an error message (general, not field-specific)
     */
    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    /**
     * Get error message
     */
    public static function getError(): ?string
    {
        return self::get('error');
    }

    /**
     * Set a warning message
     */
    public static function warning(string $message): void
    {
        self::set('warning', $message);
    }

    /**
     * Get warning message
     */
    public static function getWarning(): ?string
    {
        return self::get('warning');
    }

    /**
     * Set an info message
     */
    public static function info(string $message): void
    {
        self::set('info', $message);
    }

    /**
     * Get info message
     */
    public static function getInfo(): ?string
    {
        return self::get('info');
    }

    /**
     * Get all flash data
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::ensureSession();
        return $_SESSION[self::FLASH_KEY] ?? [];
    }

    /**
     * Clear all flash data
     */
    public static function clear(): void
    {
        self::ensureSession();
        $_SESSION[self::FLASH_KEY] = [];
        $_SESSION[self::FLASH_NEW] = [];
    }

    /**
     * Keep flash data for another request
     *
     * @param array<string>|null $keys Keys to keep, null for all
     */
    public static function keep(?array $keys = null): void
    {
        self::ensureSession();

        $current = $_SESSION[self::FLASH_KEY] ?? [];

        if ($keys === null) {
            // Keep all
            foreach ($current as $key => $value) {
                $_SESSION[self::FLASH_NEW][$key] = $value;
            }
        } else {
            // Keep specific keys
            foreach ($keys as $key) {
                if (isset($current[$key])) {
                    $_SESSION[self::FLASH_NEW][$key] = $current[$key];
                }
            }
        }
    }

    /**
     * Age flash data - move new data to current, clear old data
     * This should be called at the start of each request
     */
    public static function age(): void
    {
        self::ensureSession();

        // Move new flash data to current (available for this request)
        $_SESSION[self::FLASH_KEY] = $_SESSION[self::FLASH_NEW] ?? [];

        // Clear new flash data
        $_SESSION[self::FLASH_NEW] = [];
    }

    /**
     * Ensure session is started
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /**
     * Get flash data for templates (combines getters in a convenient format)
     *
     * @return array{
     *     success: ?string,
     *     error: ?string,
     *     warning: ?string,
     *     info: ?string,
     *     errors: array<string, array<string>>,
     *     old: array<string, mixed>
     * }
     */
    public static function forTemplate(): array
    {
        return [
            'success' => self::getSuccess(),
            'error' => self::getError(),
            'warning' => self::getWarning(),
            'info' => self::getInfo(),
            'errors' => self::getErrors(),
            'old' => self::getAllOld(),
        ];
    }
}
