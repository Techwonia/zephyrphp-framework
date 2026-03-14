<?php

declare(strict_types=1);

namespace ZephyrPHP\Support;

/**
 * String helper utilities.
 *
 * Provides common string manipulation methods used throughout the framework.
 */
class Str
{
    /**
     * Convert a string to a URL-friendly slug.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        // Transliterate common accented characters
        $transliteration = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ø' => 'o', 'æ' => 'ae',
            'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ý' => 'y', 'ÿ' => 'y',
        ];
        $value = strtr($value, $transliteration);

        // Replace non-alphanumeric characters with separator
        $value = preg_replace('/[^a-z0-9\s' . preg_quote($separator, '/') . ']/', '', $value);
        // Replace whitespace and repeated separators
        $value = preg_replace('/[\s' . preg_quote($separator, '/') . ']+/', $separator, $value);

        return trim($value, $separator);
    }

    /**
     * Truncate a string to a given length, appending a suffix.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . $end;
    }

    /**
     * Truncate a string to the nearest word boundary.
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        $parts = preg_split('/\s+/', $value, $words + 1);

        if (count($parts) <= $words) {
            return $value;
        }

        array_pop($parts);
        return implode(' ', $parts) . $end;
    }

    /**
     * Convert a string to StudlyCase (PascalCase).
     */
    public static function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        return str_replace(' ', '', ucwords($value));
    }

    /**
     * Convert a string to camelCase.
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        // Insert delimiter before uppercase letters
        $value = preg_replace('/([a-z])([A-Z])/', '$1' . $delimiter . '$2', $value);
        // Replace spaces and hyphens
        $value = preg_replace('/[\s-]+/', $delimiter, $value);

        return mb_strtolower($value);
    }

    /**
     * Convert a string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * Convert a string to Title Case.
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Convert a string to UPPER CASE.
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert a string to lower case.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Determine if a string starts with the given value(s).
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if a string ends with the given value(s).
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if a string contains the given value(s).
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the portion of a string before the first occurrence of a value.
     */
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);
        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    /**
     * Get the portion of a string after the first occurrence of a value.
     */
    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);
        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    /**
     * Get the portion of a string between two values.
     */
    public static function between(string $subject, string $from, string $to): string
    {
        return self::before(self::after($subject, $from), $to);
    }

    /**
     * Determine if a string is a valid UUID.
     */
    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /**
     * Determine if a string is a valid email address.
     */
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Determine if a string is a valid URL.
     */
    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Generate a random string of the given length.
     */
    public static function random(int $length = 16): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }

    /**
     * Replace the first occurrence of a value in a string.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * Replace the last occurrence of a value in a string.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $pos = strrpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * Mask a portion of a string with a repeated character.
     */
    public static function mask(string $value, string $character = '*', int $index = 0, ?int $length = null): string
    {
        $length ??= mb_strlen($value) - $index;
        $start = mb_substr($value, 0, $index);
        $masked = str_repeat($character, $length);
        $end = mb_substr($value, $index + $length);

        return $start . $masked . $end;
    }

    /**
     * Pad both sides of a string to a given length.
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Convert a value to a singular form (basic English rules).
     */
    public static function singular(string $value): string
    {
        if (str_ends_with($value, 'ies')) {
            return substr($value, 0, -3) . 'y';
        }
        if (str_ends_with($value, 'ses') || str_ends_with($value, 'xes') || str_ends_with($value, 'zes')) {
            return substr($value, 0, -2);
        }
        if (str_ends_with($value, 'shes') || str_ends_with($value, 'ches')) {
            return substr($value, 0, -2);
        }
        if (str_ends_with($value, 's') && !str_ends_with($value, 'ss')) {
            return substr($value, 0, -1);
        }
        return $value;
    }

    /**
     * Convert a value to a plural form (basic English rules).
     */
    public static function plural(string $value): string
    {
        if (str_ends_with($value, 'y') && !self::endsWith($value, ['ay', 'ey', 'oy', 'uy'])) {
            return substr($value, 0, -1) . 'ies';
        }
        if (str_ends_with($value, 's') || str_ends_with($value, 'x') || str_ends_with($value, 'z')
            || str_ends_with($value, 'sh') || str_ends_with($value, 'ch')) {
            return $value . 'es';
        }
        return $value . 's';
    }

    /**
     * Generate a human-readable string from a class basename.
     */
    public static function headline(string $value): string
    {
        // Convert separators to spaces
        $value = str_replace(['-', '_'], ' ', $value);
        // Insert spaces before capitals
        $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);
        return self::title($value);
    }

    /**
     * Get the class basename from a fully-qualified class name.
     */
    public static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Ensure a string starts with the given value.
     */
    public static function start(string $value, string $prefix): string
    {
        if (!str_starts_with($value, $prefix)) {
            return $prefix . $value;
        }
        return $value;
    }

    /**
     * Ensure a string ends with the given value.
     */
    public static function finish(string $value, string $suffix): string
    {
        if (!str_ends_with($value, $suffix)) {
            return $value . $suffix;
        }
        return $value;
    }

    /**
     * Determine if a string matches a given pattern (supports * wildcard).
     */
    public static function is(string|array $pattern, string $value): bool
    {
        foreach ((array) $pattern as $p) {
            if ($p === $value) {
                return true;
            }

            $p = preg_quote($p, '#');
            $p = str_replace('\*', '.*', $p);

            if (preg_match('#^' . $p . '$#u', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the length of a string (multibyte safe).
     */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * Extract an excerpt from a string around a given phrase.
     */
    public static function excerpt(string $text, string $phrase, int $radius = 100, string $omission = '...'): string
    {
        $pos = mb_stripos($text, $phrase);
        if ($pos === false) {
            return self::limit($text, $radius * 2, $omission);
        }

        $start = max(0, $pos - $radius);
        $end = min(mb_strlen($text), $pos + mb_strlen($phrase) + $radius);

        $excerpt = mb_substr($text, $start, $end - $start);

        if ($start > 0) {
            $excerpt = $omission . ltrim($excerpt);
        }
        if ($end < mb_strlen($text)) {
            $excerpt = rtrim($excerpt) . $omission;
        }

        return $excerpt;
    }
}
