<?php

declare(strict_types=1);

namespace ZephyrPHP\Support;

/**
 * Array helper utilities.
 */
class Arr
{
    /**
     * Get a value from a nested array using dot notation.
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Set a value in a nested array using dot notation.
     */
    public static function set(array &$array, string $key, mixed $value): array
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }

        return $array;
    }

    /**
     * Check if a key exists using dot notation.
     */
    public static function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }

        return true;
    }

    /**
     * Remove a key from a nested array using dot notation.
     */
    public static function forget(array &$array, string|array $keys): void
    {
        foreach ((array) $keys as $key) {
            $parts = explode('.', $key);
            $current = &$array;

            while (count($parts) > 1) {
                $part = array_shift($parts);
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    continue 2;
                }
                $current = &$current[$part];
            }

            unset($current[array_shift($parts)]);
        }
    }

    /**
     * Get a subset of items from an array.
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Get all items except the specified keys.
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Pluck a single column from a nested array.
     */
    public static function pluck(array $array, string $value, ?string $key = null): array
    {
        $results = [];

        foreach ($array as $item) {
            $itemValue = is_object($item)
                ? ($item->$value ?? null)
                : ($item[$value] ?? null);

            if ($key !== null) {
                $itemKey = is_object($item)
                    ? ($item->$key ?? null)
                    : ($item[$key] ?? null);
                $results[$itemKey] = $itemValue;
            } else {
                $results[] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, self::flatten($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Flatten a nested array into dot-notation keys.
     */
    public static function dot(array $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, self::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * Expand a dot-notation array into a nested array.
     */
    public static function undot(array $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            self::set($results, (string) $key, $value);
        }

        return $results;
    }

    /**
     * Return the first element matching a truth test.
     */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }
            return reset($array);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Return the last element matching a truth test.
     */
    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }
            return end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Filter an array using a callback.
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter an array where a key equals a value.
     */
    public static function whereEquals(array $array, string $key, mixed $value): array
    {
        return array_filter($array, function ($item) use ($key, $value) {
            $itemValue = is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null);
            return $itemValue === $value;
        });
    }

    /**
     * Group an array by a key.
     */
    public static function groupBy(array $array, string $key): array
    {
        $results = [];

        foreach ($array as $item) {
            $groupKey = is_object($item) ? ($item->$key ?? '') : ($item[$key] ?? '');
            $results[$groupKey][] = $item;
        }

        return $results;
    }

    /**
     * Key an array by a field.
     */
    public static function keyBy(array $array, string $key): array
    {
        $results = [];

        foreach ($array as $item) {
            $itemKey = is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null);
            if ($itemKey !== null) {
                $results[$itemKey] = $item;
            }
        }

        return $results;
    }

    /**
     * Sort an array by a key.
     */
    public static function sortBy(array $array, string $key, string $direction = 'asc'): array
    {
        usort($array, function ($a, $b) use ($key, $direction) {
            $aVal = is_object($a) ? ($a->$key ?? null) : ($a[$key] ?? null);
            $bVal = is_object($b) ? ($b->$key ?? null) : ($b[$key] ?? null);
            $result = $aVal <=> $bVal;
            return $direction === 'desc' ? -$result : $result;
        });

        return $array;
    }

    /**
     * Determine if all items pass a truth test.
     */
    public static function every(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine if any items pass a truth test.
     */
    public static function some(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get a random element from an array.
     */
    public static function random(array $array, int $count = 1): mixed
    {
        if (empty($array)) {
            return $count === 1 ? null : [];
        }

        $keys = array_rand($array, min($count, count($array)));

        if ($count === 1) {
            return $array[$keys];
        }

        return array_map(fn($key) => $array[$key], (array) $keys);
    }

    /**
     * Collapse an array of arrays into a single array.
     */
    public static function collapse(array $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if (is_array($values)) {
                $results = array_merge($results, $values);
            }
        }

        return $results;
    }

    /**
     * Map over each item and return a new array.
     */
    public static function map(array $array, callable $callback): array
    {
        $keys = array_keys($array);
        $values = array_map($callback, $array, $keys);
        return array_combine($keys, $values);
    }

    /**
     * Wrap a value in an array if it is not already one.
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }

    /**
     * Return the values of an array as a re-indexed array.
     */
    public static function values(array $array): array
    {
        return array_values($array);
    }

    /**
     * Recursively merge arrays (like array_merge_recursive but overwrites scalars).
     */
    public static function mergeDeep(array ...$arrays): array
    {
        $result = array_shift($arrays);

        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                    $result[$key] = self::mergeDeep($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }
}
