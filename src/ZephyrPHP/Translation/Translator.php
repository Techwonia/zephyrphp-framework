<?php

declare(strict_types=1);

namespace ZephyrPHP\Translation;

use ZephyrPHP\Config\Config;

/**
 * Translation/i18n system.
 *
 * Loads translations from JSON or PHP files and provides lookup with
 * parameter replacement and pluralization.
 *
 * Usage:
 *   $translator = new Translator('en', BASE_PATH . '/lang');
 *   $translator->get('messages.welcome');           // "Welcome!"
 *   $translator->get('messages.hello', ['name' => 'John']); // "Hello, John!"
 *   $translator->choice('messages.apples', 5);      // "5 apples"
 *
 * File structure:
 *   lang/en/messages.php   — returns ['welcome' => 'Welcome!', ...]
 *   lang/en/messages.json  — {"welcome": "Welcome!", ...}
 */
class Translator
{
    private static ?Translator $instance = null;

    protected string $locale;
    protected string $fallback;
    protected string $path;
    protected array $loaded = [];

    public function __construct(?string $locale = null, ?string $path = null, ?string $fallback = null)
    {
        $this->locale = $locale ?? Config::get('app.locale', 'en');
        $this->fallback = $fallback ?? Config::get('app.fallback_locale', 'en');
        $this->path = $path ?? (defined('BASE_PATH') ? BASE_PATH . '/lang' : getcwd() . '/lang');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a translated string.
     *
     * @param string $key Dot-notation key: "group.key" or "group.nested.key"
     * @param array $replace Replacement parameters [:name => value]
     * @param string|null $locale Override locale
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;

        $value = $this->resolve($key, $locale);

        // Try fallback locale
        if ($value === null && $locale !== $this->fallback) {
            $value = $this->resolve($key, $this->fallback);
        }

        // Return key if no translation found
        if ($value === null) {
            return $key;
        }

        return $this->replaceParameters($value, $replace);
    }

    /**
     * Get a translation with pluralization.
     *
     * Translation format: "one apple|:count apples"
     * Or with ranges: "{0} no apples|{1} one apple|[2,*] :count apples"
     */
    public function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        $line = $this->get($key, [], $locale);

        if ($line === $key) {
            return $key;
        }

        $replace['count'] = (string) $count;
        $value = $this->selectPlural($line, $count);

        return $this->replaceParameters($value, $replace);
    }

    /**
     * Check if a translation exists.
     */
    public function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?? $this->locale;
        return $this->resolve($key, $locale) !== null;
    }

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Get the current locale.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Set the fallback locale.
     */
    public function setFallback(string $locale): void
    {
        $this->fallback = $locale;
    }

    /**
     * Get the fallback locale.
     */
    public function getFallback(): string
    {
        return $this->fallback;
    }

    /**
     * Get all loaded translations for a locale and group.
     */
    public function all(string $group, ?string $locale = null): array
    {
        $locale = $locale ?? $this->locale;
        $this->loadGroup($group, $locale);
        return $this->loaded[$locale][$group] ?? [];
    }

    /**
     * Add translations at runtime.
     */
    public function addTranslations(string $group, array $translations, ?string $locale = null): void
    {
        $locale = $locale ?? $this->locale;

        if (!isset($this->loaded[$locale])) {
            $this->loaded[$locale] = [];
        }

        if (!isset($this->loaded[$locale][$group])) {
            $this->loaded[$locale][$group] = [];
        }

        $this->loaded[$locale][$group] = array_merge($this->loaded[$locale][$group], $translations);
    }

    /**
     * Resolve a translation key to its value.
     */
    protected function resolve(string $key, string $locale): ?string
    {
        $segments = explode('.', $key);
        $group = array_shift($segments);

        $this->loadGroup($group, $locale);

        $value = $this->loaded[$locale][$group] ?? [];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Load a translation group from file.
     */
    protected function loadGroup(string $group, string $locale): void
    {
        if (isset($this->loaded[$locale][$group])) {
            return;
        }

        if (!isset($this->loaded[$locale])) {
            $this->loaded[$locale] = [];
        }

        // Try PHP file first
        $phpFile = $this->path . '/' . $locale . '/' . $group . '.php';
        if (file_exists($phpFile)) {
            $data = require $phpFile;
            if (is_array($data)) {
                $this->loaded[$locale][$group] = $data;
                return;
            }
        }

        // Try JSON file
        $jsonFile = $this->path . '/' . $locale . '/' . $group . '.json';
        if (file_exists($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $this->loaded[$locale][$group] = $data;
                return;
            }
        }

        // Try single locale JSON file (lang/en.json with all groups)
        $localeFile = $this->path . '/' . $locale . '.json';
        if (file_exists($localeFile)) {
            $content = file_get_contents($localeFile);
            $data = json_decode($content, true);
            if (is_array($data) && isset($data[$group])) {
                $this->loaded[$locale][$group] = $data[$group];
                return;
            }
        }

        $this->loaded[$locale][$group] = [];
    }

    /**
     * Replace :parameter placeholders in a translation string.
     */
    protected function replaceParameters(string $line, array $replace): string
    {
        if (empty($replace)) {
            return $line;
        }

        foreach ($replace as $key => $value) {
            $line = str_replace(
                [':' . $key, ':' . strtoupper($key), ':' . ucfirst($key)],
                [(string) $value, strtoupper((string) $value), ucfirst((string) $value)],
                $line
            );
        }

        return $line;
    }

    /**
     * Select the correct plural form from a pipe-delimited string.
     *
     * Supports:
     *   "one|many"
     *   "{0} none|{1} one|[2,*] many"
     *   "{0} none|[1,5] few|[6,*] many"
     */
    protected function selectPlural(string $line, int $count): string
    {
        $segments = explode('|', $line);

        // Check for explicit range/value syntax
        foreach ($segments as $segment) {
            $segment = trim($segment);

            // {n} exact match
            if (preg_match('/^\{(\d+)\}\s*(.*)$/', $segment, $matches)) {
                if ((int) $matches[1] === $count) {
                    return trim($matches[2]);
                }
                continue;
            }

            // [min,max] or [min,*] range
            if (preg_match('/^\[(\d+),\s*(\d+|\*)\]\s*(.*)$/', $segment, $matches)) {
                $min = (int) $matches[1];
                $max = $matches[2] === '*' ? PHP_INT_MAX : (int) $matches[2];
                if ($count >= $min && $count <= $max) {
                    return trim($matches[3]);
                }
                continue;
            }
        }

        // Simple pipe syntax: "singular|plural"
        $segments = array_map('trim', $segments);

        if (count($segments) === 2) {
            return $count === 1 ? $segments[0] : $segments[1];
        }

        // Three segments: "zero|one|many"
        if (count($segments) === 3) {
            if ($count === 0) {
                return $segments[0];
            }
            return $count === 1 ? $segments[1] : $segments[2];
        }

        return $segments[0] ?? $line;
    }
}
