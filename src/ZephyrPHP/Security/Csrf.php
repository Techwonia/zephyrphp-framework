<?php

namespace ZephyrPHP\Security;

class Csrf
{
    private const TOKEN_LENGTH = 32;
    private const TOKEN_KEY = 'csrf_token';
    private const TOKEN_TIME_KEY = 'csrf_token_time';
    private const TOKEN_LIFETIME = 3600; // 1 hour

    public static function generate(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_KEY] = $token;
        $_SESSION[self::TOKEN_TIME_KEY] = time();

        return $token;
    }

    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_KEY]) || self::isExpired()) {
            return self::generate();
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    public static function validate(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || !isset($_SESSION[self::TOKEN_KEY])) {
            return false;
        }

        if (self::isExpired()) {
            self::regenerate();
            return false;
        }

        $valid = hash_equals($_SESSION[self::TOKEN_KEY], $token);

        // Rotate token after successful validation to prevent replay attacks
        if ($valid) {
            self::regenerate();
        }

        return $valid;
    }

    public static function regenerate(): string
    {
        return self::generate();
    }

    private static function isExpired(): bool
    {
        if (!isset($_SESSION[self::TOKEN_TIME_KEY])) {
            return true;
        }

        return (time() - $_SESSION[self::TOKEN_TIME_KEY]) > self::TOKEN_LIFETIME;
    }

    public static function getHiddenInput(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function getMetaTag(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }
}
