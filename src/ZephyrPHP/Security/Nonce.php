<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * CSP Nonce Generator
 *
 * Generates cryptographically secure nonces for Content Security Policy.
 * Nonces allow inline scripts and styles while maintaining strict CSP.
 *
 * Usage:
 *   // Generate nonce for current request
 *   $nonce = Nonce::generate();
 *
 *   // In template: <script nonce="{{ csp_nonce() }}">...</script>
 *   // In CSP header: script-src 'nonce-{$nonce}'
 */
class Nonce
{
    /** @var string|null Current request nonce */
    private static ?string $nonce = null;

    /** @var string|null Separate nonce for styles (optional) */
    private static ?string $styleNonce = null;

    /** @var bool Whether to use separate nonces for scripts and styles */
    private static bool $separateStyleNonce = false;

    /** @var int Nonce length in bytes (will be base64 encoded) */
    private const NONCE_BYTES = 16;

    /**
     * Generate or retrieve the current request's nonce
     *
     * The same nonce is reused for the entire request to ensure
     * consistency between the CSP header and inline elements.
     */
    public static function generate(): string
    {
        if (self::$nonce === null) {
            self::$nonce = self::createNonce();
        }

        return self::$nonce;
    }

    /**
     * Get the script nonce (alias for generate)
     */
    public static function script(): string
    {
        return self::generate();
    }

    /**
     * Get the style nonce
     *
     * Returns a separate nonce if separateStyleNonce is enabled,
     * otherwise returns the same nonce as scripts.
     */
    public static function style(): string
    {
        if (!self::$separateStyleNonce) {
            return self::generate();
        }

        if (self::$styleNonce === null) {
            self::$styleNonce = self::createNonce();
        }

        return self::$styleNonce;
    }

    /**
     * Enable separate nonces for scripts and styles
     *
     * Some security policies require different nonces for scripts and styles.
     */
    public static function useSeparateStyleNonce(bool $separate = true): void
    {
        self::$separateStyleNonce = $separate;
    }

    /**
     * Get the nonce formatted for CSP header
     *
     * @return string e.g., 'nonce-abc123...'
     */
    public static function forCsp(): string
    {
        return "'nonce-" . self::generate() . "'";
    }

    /**
     * Get the style nonce formatted for CSP header
     */
    public static function styleForCsp(): string
    {
        return "'nonce-" . self::style() . "'";
    }

    /**
     * Get nonce attribute for HTML elements
     *
     * @return string e.g., 'nonce="abc123..."'
     */
    public static function attribute(): string
    {
        return 'nonce="' . htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Get style nonce attribute for HTML elements
     */
    public static function styleAttribute(): string
    {
        return 'nonce="' . htmlspecialchars(self::style(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Check if a nonce has been generated for this request
     */
    public static function hasNonce(): bool
    {
        return self::$nonce !== null;
    }

    /**
     * Reset nonces (useful for testing or long-running processes)
     */
    public static function reset(): void
    {
        self::$nonce = null;
        self::$styleNonce = null;
    }

    /**
     * Verify if a given nonce matches the current request's nonce
     *
     * @param string $nonce The nonce to verify
     * @param string $type 'script' or 'style'
     */
    public static function verify(string $nonce, string $type = 'script'): bool
    {
        $expected = $type === 'style' ? self::style() : self::generate();
        return hash_equals($expected, $nonce);
    }

    /**
     * Create a new cryptographically secure nonce
     */
    private static function createNonce(): string
    {
        return base64_encode(random_bytes(self::NONCE_BYTES));
    }

    /**
     * Get all nonces as an array (useful for meta tags)
     *
     * @return array{script: string, style: string}
     */
    public static function all(): array
    {
        return [
            'script' => self::generate(),
            'style' => self::style(),
        ];
    }
}
