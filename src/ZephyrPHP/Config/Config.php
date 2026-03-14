<?php

declare(strict_types=1);

namespace ZephyrPHP\Config;

class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        // Try loading from cache first
        if (self::loadFromCache()) {
            return;
        }

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

    /**
     * Cache all loaded configuration to a single PHP file.
     *
     * Usage (via craftsman command or manually):
     *   Config::load(BASE_PATH . '/config');
     *   Config::cache();
     */
    public static function cache(): bool
    {
        $cachePath = self::getCachePath();
        $dir = dirname($cachePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = '<?php return ' . var_export(self::$config, true) . ';' . PHP_EOL;

        return file_put_contents($cachePath, $content, LOCK_EX) !== false;
    }

    /**
     * Load configuration from the cache file.
     */
    protected static function loadFromCache(): bool
    {
        $cachePath = self::getCachePath();

        if (!file_exists($cachePath)) {
            return false;
        }

        $data = require $cachePath;

        if (is_array($data)) {
            self::$config = $data;
            self::$loaded = true;
            return true;
        }

        return false;
    }

    /**
     * Remove the configuration cache file.
     */
    public static function clearCache(): bool
    {
        $cachePath = self::getCachePath();

        if (file_exists($cachePath)) {
            return unlink($cachePath);
        }

        return true;
    }

    /**
     * Check if a configuration cache file exists.
     */
    public static function isCached(): bool
    {
        return file_exists(self::getCachePath());
    }

    /**
     * Get the path to the config cache file.
     */
    protected static function getCachePath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/storage/cache/config.php';
    }
}
