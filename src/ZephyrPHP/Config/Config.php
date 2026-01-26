<?php

declare(strict_types=1);

namespace ZephyrPHP\Config;

class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $values = require $file;

            if (is_array($values)) {
                self::$config[$key] = $values;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        if (!self::$loaded && defined('BASE_PATH')) {
            self::load(BASE_PATH . '/config');
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return self::getFromEnv($key, $default);
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function getFromEnv(string $key, $default = null)
    {
        $envKey = strtoupper(str_replace('.', '_', $key));
        return $_ENV[$envKey] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &self::$config;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $config[$segment] = $value;
            } else {
                if (!isset($config[$segment]) || !is_array($config[$segment])) {
                    $config[$segment] = [];
                }
                $config = &$config[$segment];
            }
        }
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function all(): array
    {
        return self::$config;
    }

    public static function push(string $key, $value): void
    {
        $array = self::get($key, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        $array[] = $value;
        self::set($key, $array);
    }

    public static function prepend(string $key, $value): void
    {
        $array = self::get($key, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        array_unshift($array, $value);
        self::set($key, $array);
    }

    public static function merge(string $key, array $values): void
    {
        $existing = self::get($key, []);

        if (!is_array($existing)) {
            $existing = [];
        }

        self::set($key, array_merge($existing, $values));
    }

    public static function reset(): void
    {
        self::$config = [];
        self::$loaded = false;
    }
}
