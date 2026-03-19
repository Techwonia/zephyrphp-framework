<?php

namespace ZephyrPHP\Security;

class Sanitizer
{
    public static function string(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    public static function email(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return filter_var(trim($value), FILTER_SANITIZE_EMAIL) ?: '';
    }

    public static function int($value): int
    {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function float($value): float
    {
        return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    public static function url(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return filter_var(trim($value), FILTER_SANITIZE_URL) ?: '';
    }

    public static function alphanumeric(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return preg_replace('/[^a-zA-Z0-9]/', '', $value);
    }

    public static function slug(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-]/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-');
    }

    public static function filename(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        // Remove path traversal attempts and dangerous characters
        $value = basename($value);
        $value = preg_replace('/[^a-zA-Z0-9._-]/', '', $value);
        return $value;
    }

    public static function array(array $data, array $rules = []): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $rule = $rules[$key] ?? 'string';
            if (is_array($value)) {
                $sanitized[$key] = self::array($value, $rules[$key] ?? []);
            } else {
                $sanitized[$key] = match ($rule) {
                    'int', 'integer' => self::int($value),
                    'float', 'double' => self::float($value),
                    'email' => self::email($value),
                    'url' => self::url($value),
                    'alphanumeric' => self::alphanumeric($value),
                    'slug' => self::slug($value),
                    'filename' => self::filename($value),
                    'none' => (function() use ($value) {
                        trigger_error(
                            "Sanitizer rule 'none' skips sanitization — use with extreme caution. "
                            . "The 'raw' alias has been removed for security.",
                            E_USER_DEPRECATED
                        );
                        return $value;
                    })(),
                    default => self::string($value),
                };
            }
        }
        return $sanitized;
    }

    public static function stripTags(?string $value, array $allowedTags = []): string
    {
        if ($value === null) {
            return '';
        }
        $allowed = implode('', array_map(fn($tag) => "<$tag>", $allowedTags));
        return strip_tags($value, $allowed);
    }
}
