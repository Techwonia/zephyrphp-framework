<?php

declare(strict_types=1);

namespace ZephyrPHP\Asset;

/**
 * Advanced Asset Manager for ZephyrPHP
 *
 * Provides a comprehensive API for managing CSS, JavaScript, and other assets
 * with support for:
 * - Multiple versioning strategies (timestamp, hash, manifest, global)
 * - CDN integration with automatic URL prefixing
 * - Asset collections/bundles with priority ordering
 * - Subresource Integrity (SRI) for security
 * - Build tool integration (Vite, Webpack)
 * - Preloading and resource hints
 * - Environment-aware configuration (dev/production)
 */
class Asset
{
    private static ?Asset $instance = null;

    /** @var array<string, mixed> Configuration options */
    private static array $config = [
        'base_url' => null,
        'base_path' => null,
        'cdn_url' => null,
        'cdn_enabled' => true,
        'version_strategy' => 'timestamp',
        'global_version' => '1.0.0',
        'integrity' => false,
        'minify' => false,
        'environment' => 'development',
        'assets_prefix' => 'assets',
    ];

    /** @var array<string, string> Manifest data from build tools */
    private static array $manifest = [];

    /** @var array<string, array> Registered asset collections */
    private static array $collections = [];

    /** @var array<string, array> Assets queued for output */
    private static array $queued = [
        'css' => [],
        'js' => [],
        'js_head' => [],
        'preload' => [],
    ];

    /** @var array<string, string> Inline assets */
    private static array $inline = [
        'css' => '',
        'js' => '',
        'js_head' => '',
    ];

    /** @var array<string, string> Integrity hashes cache */
    private static array $integrityCache = [];

    /** @var array<string, string> URL cache for performance */
    private static array $urlCache = [];

    /** @var array<string, array> Registered external CDN assets */
    private static array $externals = [];

    /** @var bool Whether assets have been rendered */
    private static bool $cssRendered = false;
    private static bool $jsRendered = false;

    private function __construct() {}

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Configure the asset manager
     *
     * @param array $options Configuration options:
     *   - base_url: Base URL for assets (auto-detected if not set)
     *   - base_path: Filesystem path to public directory
     *   - cdn_url: CDN URL prefix (null to disable)
     *   - cdn_enabled: Whether to use CDN URLs (default: true)
     *   - version_strategy: 'timestamp', 'hash', 'manifest', 'global', 'none'
     *   - global_version: Version string for 'global' strategy
     *   - integrity: Enable SRI hashes (default: false)
     *   - minify: Auto-use .min.css/.min.js in production (default: false)
     *   - environment: 'development' or 'production'
     *   - assets_prefix: Prefix for asset paths (default: 'assets')
     */
    public static function configure(array $options): void
    {
        foreach ($options as $key => $value) {
            if (array_key_exists($key, self::$config)) {
                self::$config[$key] = $value;
            }
        }

        // Normalize URLs
        if (self::$config['base_url'] !== null) {
            self::$config['base_url'] = rtrim(self::$config['base_url'], '/');
        }
        if (self::$config['cdn_url'] !== null) {
            self::$config['cdn_url'] = rtrim(self::$config['cdn_url'], '/');
        }
        if (self::$config['base_path'] !== null) {
            self::$config['base_path'] = rtrim(self::$config['base_path'], '/\\');
        }

        // Auto-detect environment
        if (!isset($options['environment'])) {
            self::$config['environment'] = ($_ENV['ENV'] ?? 'dev') === 'production' ? 'production' : 'development';
        }
    }

    /**
     * Get a configuration value
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Check if running in production mode
     */
    public static function isProduction(): bool
    {
        return self::$config['environment'] === 'production';
    }

    /**
     * Load a manifest file from a build tool (Vite, Webpack, etc.)
     */
    public static function loadManifest(string $path): bool
    {
        $fullPath = self::resolvePath($path);

        if (!file_exists($fullPath)) {
            return false;
        }

        $content = file_get_contents($fullPath);
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            self::$manifest = $data;
            return true;
        }

        return false;
    }

    /**
     * Get the URL for an asset
     *
     * @param string $path Asset path relative to public directory
     * @param array $options Options: version, integrity, absolute, cdn
     */
    public static function url(string $path, array $options = []): string
    {
        // Check cache first
        $cacheKey = $path . serialize($options);
        if (isset(self::$urlCache[$cacheKey])) {
            return self::$urlCache[$cacheKey];
        }

        $cleanPath = self::normalizePath($path);

        // Check manifest first (for build tool integration)
        if (!empty(self::$manifest)) {
            $cleanPath = self::resolveFromManifest($cleanPath);
        }

        // Handle minified versions in production
        if (self::$config['minify'] && self::isProduction()) {
            $cleanPath = self::getMinifiedPath($cleanPath);
        }

        // Determine base URL (CDN or local)
        $useCdn = ($options['cdn'] ?? true) && self::$config['cdn_enabled'] && self::$config['cdn_url'];
        $baseUrl = $useCdn ? self::$config['cdn_url'] : self::getBaseUrl();

        $url = $baseUrl . '/' . $cleanPath;

        // Add version query string
        if (self::$config['version_strategy'] !== 'none') {
            $version = $options['version'] ?? self::getVersion($cleanPath);
            if ($version) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator . 'v=' . $version;
            }
        }

        // Cache and return
        self::$urlCache[$cacheKey] = $url;
        return $url;
    }

    /**
     * Normalize asset path - ensures consistent path format
     */
    private static function normalizePath(string $path): string
    {
        // Remove leading slashes
        $path = ltrim($path, '/');

        // Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        return $path;
    }

    /**
     * Get minified version path if it exists
     */
    private static function getMinifiedPath(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if (!in_array($ext, ['css', 'js'])) {
            return $path;
        }

        // Check if already minified
        if (str_ends_with($path, '.min.' . $ext)) {
            return $path;
        }

        $minPath = preg_replace('/\.' . $ext . '$/', '.min.' . $ext, $path);
        $fullPath = self::resolvePath($minPath);

        return file_exists($fullPath) ? $minPath : $path;
    }

    /**
     * Generate a CSS link tag
     */
    public static function css(string $path, array $attributes = []): string
    {
        $url = self::url($path);

        $attrs = array_merge([
            'rel' => 'stylesheet',
            'href' => $url,
        ], $attributes);

        // Add integrity if enabled
        if (self::$config['integrity'] && !isset($attrs['integrity'])) {
            $integrity = self::getIntegrity($path);
            if ($integrity) {
                $attrs['integrity'] = $integrity;
                $attrs['crossorigin'] = $attrs['crossorigin'] ?? 'anonymous';
            }
        }

        return '<link ' . self::buildAttributes($attrs) . '>';
    }

    /**
     * Generate a JavaScript script tag
     */
    public static function js(string $path, array $attributes = []): string
    {
        $url = self::url($path);

        $attrs = array_merge([
            'src' => $url,
        ], $attributes);

        // Add integrity if enabled
        if (self::$config['integrity'] && !isset($attrs['integrity'])) {
            $integrity = self::getIntegrity($path);
            if ($integrity) {
                $attrs['integrity'] = $integrity;
                $attrs['crossorigin'] = $attrs['crossorigin'] ?? 'anonymous';
            }
        }

        return '<script ' . self::buildAttributes($attrs) . '></script>';
    }

    /**
     * Register an external CDN asset
     *
     * @param string $name Unique identifier for the asset
     * @param array $config Configuration: url, integrity, version, crossorigin
     */
    public static function external(string $name, array $config): void
    {
        self::$externals[$name] = $config;
    }

    /**
     * Register multiple external CDN assets at once
     *
     * @param array<string, array> $assets Array of name => config pairs
     */
    public static function externals(array $assets): void
    {
        foreach ($assets as $name => $config) {
            self::external($name, $config);
        }
    }

    /**
     * Get an external asset's URL
     */
    public static function externalUrl(string $name): ?string
    {
        return self::$externals[$name]['url'] ?? null;
    }

    /**
     * Generate CSS link tag for external CDN asset
     *
     * @param string $nameOrUrl Asset name (registered) or full URL
     * @param array $attributes Additional HTML attributes
     */
    public static function externalCss(string $nameOrUrl, array $attributes = []): string
    {
        // Check if it's a registered external asset
        if (isset(self::$externals[$nameOrUrl])) {
            $config = self::$externals[$nameOrUrl];
            $url = $config['url'];

            // Add integrity if configured
            if (isset($config['integrity'])) {
                $attributes['integrity'] = $config['integrity'];
                $attributes['crossorigin'] = $config['crossorigin'] ?? 'anonymous';
            }
        } else {
            // Treat as direct URL
            $url = $nameOrUrl;
        }

        $attrs = array_merge([
            'rel' => 'stylesheet',
            'href' => $url,
        ], $attributes);

        return '<link ' . self::buildAttributes($attrs) . '>';
    }

    /**
     * Generate JavaScript script tag for external CDN asset
     *
     * @param string $nameOrUrl Asset name (registered) or full URL
     * @param array $attributes Additional HTML attributes
     */
    public static function externalJs(string $nameOrUrl, array $attributes = []): string
    {
        // Check if it's a registered external asset
        if (isset(self::$externals[$nameOrUrl])) {
            $config = self::$externals[$nameOrUrl];
            $url = $config['url'];

            // Add integrity if configured
            if (isset($config['integrity'])) {
                $attributes['integrity'] = $config['integrity'];
                $attributes['crossorigin'] = $config['crossorigin'] ?? 'anonymous';
            }
        } else {
            // Treat as direct URL
            $url = $nameOrUrl;
        }

        $attrs = array_merge([
            'src' => $url,
        ], $attributes);

        return '<script ' . self::buildAttributes($attrs) . '></script>';
    }

    /**
     * Queue an external CSS file for later output
     */
    public static function enqueueExternalCss(string $nameOrUrl, array $attributes = [], int $priority = 10): void
    {
        self::$queued['css']['external:' . $nameOrUrl] = [
            'path' => $nameOrUrl,
            'attributes' => $attributes,
            'priority' => $priority,
            'external' => true,
        ];
    }

    /**
     * Queue an external JavaScript file for later output
     */
    public static function enqueueExternalJs(string $nameOrUrl, array $attributes = [], bool $inHead = false, int $priority = 10): void
    {
        $key = $inHead ? 'js_head' : 'js';
        self::$queued[$key]['external:' . $nameOrUrl] = [
            'path' => $nameOrUrl,
            'attributes' => $attributes,
            'priority' => $priority,
            'external' => true,
        ];
    }

    /**
     * Check if an external asset is registered
     */
    public static function hasExternal(string $name): bool
    {
        return isset(self::$externals[$name]);
    }

    /**
     * Get all registered external assets
     */
    public static function getExternals(): array
    {
        return self::$externals;
    }

    /**
     * Generate an image tag with lazy loading support
     */
    public static function image(string $path, ?string $alt = null, array $attributes = []): string
    {
        $url = self::url($path, ['cdn' => true]);

        $attrs = array_merge([
            'src' => $url,
            'alt' => $alt ?? '',
        ], $attributes);

        // Add lazy loading by default for non-critical images
        if (!isset($attrs['loading']) && !isset($attrs['fetchpriority'])) {
            $attrs['loading'] = 'lazy';
        }

        // Auto-detect dimensions if file exists and not already set
        if (!isset($attrs['width']) || !isset($attrs['height'])) {
            $fullPath = self::resolvePath($path);
            if (file_exists($fullPath)) {
                $size = @getimagesize($fullPath);
                if ($size) {
                    $attrs['width'] = $attrs['width'] ?? $size[0];
                    $attrs['height'] = $attrs['height'] ?? $size[1];
                }
            }
        }

        return '<img ' . self::buildAttributes($attrs) . '>';
    }

    /**
     * Generate a preload link tag
     */
    public static function preload(string $path, string $as, array $attributes = []): string
    {
        $url = self::url($path);

        $attrs = array_merge([
            'rel' => 'preload',
            'href' => $url,
            'as' => $as,
        ], $attributes);

        // Add type for fonts
        if ($as === 'font' && !isset($attrs['type'])) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $fontTypes = [
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'ttf' => 'font/ttf',
                'otf' => 'font/otf',
                'eot' => 'application/vnd.ms-fontobject',
            ];
            if (isset($fontTypes[$ext])) {
                $attrs['type'] = $fontTypes[$ext];
            }
            $attrs['crossorigin'] = $attrs['crossorigin'] ?? 'anonymous';
        }

        // Add type for stylesheets
        if ($as === 'style') {
            $attrs['type'] = $attrs['type'] ?? 'text/css';
        }

        return '<link ' . self::buildAttributes($attrs) . '>';
    }

    /**
     * Generate a prefetch link tag (for assets needed on next navigation)
     */
    public static function prefetch(string $path, string $as = 'script'): string
    {
        $url = self::url($path);
        return '<link rel="prefetch" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" as="' . $as . '">';
    }

    /**
     * Generate a preconnect link tag (for CDN/external domains)
     */
    public static function preconnect(string $url, bool $crossorigin = false): string
    {
        $attrs = ['rel' => 'preconnect', 'href' => $url];
        if ($crossorigin) {
            $attrs['crossorigin'] = 'anonymous';
        }
        return '<link ' . self::buildAttributes($attrs) . '>';
    }

    /**
     * Queue a CSS file for later output
     */
    public static function enqueueCss(string $path, array $attributes = [], int $priority = 10): void
    {
        self::$queued['css'][$path] = [
            'path' => $path,
            'attributes' => $attributes,
            'priority' => $priority,
        ];
    }

    /**
     * Queue a JavaScript file for later output
     */
    public static function enqueueJs(string $path, array $attributes = [], bool $inHead = false, int $priority = 10): void
    {
        $key = $inHead ? 'js_head' : 'js';
        self::$queued[$key][$path] = [
            'path' => $path,
            'attributes' => $attributes,
            'priority' => $priority,
        ];
    }

    /**
     * Queue a preload resource
     */
    public static function enqueuePreload(string $path, string $as, array $attributes = []): void
    {
        self::$queued['preload'][$path] = [
            'path' => $path,
            'as' => $as,
            'attributes' => $attributes,
        ];
    }

    /**
     * Add inline CSS
     */
    public static function inlineCss(string $css): void
    {
        self::$inline['css'] .= trim($css) . "\n";
    }

    /**
     * Add inline JavaScript
     */
    public static function inlineJs(string $js, bool $inHead = false): void
    {
        $key = $inHead ? 'js_head' : 'js';
        self::$inline[$key] .= trim($js) . "\n";
    }

    /**
     * Render all queued preloads
     */
    public static function renderPreloads(): string
    {
        $output = '';

        foreach (self::$queued['preload'] as $item) {
            $output .= self::preload($item['path'], $item['as'], $item['attributes']) . "\n";
        }

        return $output;
    }

    /**
     * Render all queued CSS
     */
    public static function renderCss(): string
    {
        if (self::$cssRendered) {
            return '';
        }

        $output = '';

        // Sort by priority
        $items = self::$queued['css'];
        uasort($items, fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($items as $item) {
            if (!empty($item['external'])) {
                $output .= self::externalCss($item['path'], $item['attributes']) . "\n";
            } else {
                $output .= self::css($item['path'], $item['attributes']) . "\n";
            }
        }

        // Add inline CSS
        if (!empty(trim(self::$inline['css']))) {
            $output .= '<style>' . "\n" . self::$inline['css'] . '</style>' . "\n";
        }

        self::$cssRendered = true;
        return $output;
    }

    /**
     * Render all queued JavaScript for head
     */
    public static function renderJsHead(): string
    {
        $output = '';

        $items = self::$queued['js_head'];
        uasort($items, fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($items as $item) {
            if (!empty($item['external'])) {
                $output .= self::externalJs($item['path'], $item['attributes']) . "\n";
            } else {
                $output .= self::js($item['path'], $item['attributes']) . "\n";
            }
        }

        // Add inline JS for head
        if (!empty(trim(self::$inline['js_head']))) {
            $output .= '<script>' . "\n" . self::$inline['js_head'] . '</script>' . "\n";
        }

        return $output;
    }

    /**
     * Render all queued JavaScript for body
     */
    public static function renderJs(): string
    {
        if (self::$jsRendered) {
            return '';
        }

        $output = '';

        $items = self::$queued['js'];
        uasort($items, fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($items as $item) {
            if (!empty($item['external'])) {
                $output .= self::externalJs($item['path'], $item['attributes']) . "\n";
            } else {
                $output .= self::js($item['path'], $item['attributes']) . "\n";
            }
        }

        // Add inline JS
        if (!empty(trim(self::$inline['js']))) {
            $output .= '<script>' . "\n" . self::$inline['js'] . '</script>' . "\n";
        }

        self::$jsRendered = true;
        return $output;
    }

    /**
     * Register an asset collection (bundle)
     */
    public static function collection(string $name, array $assets): void
    {
        self::$collections[$name] = $assets;
    }

    /**
     * Load a registered collection
     */
    public static function load(string $name): void
    {
        if (!isset(self::$collections[$name])) {
            return;
        }

        foreach (self::$collections[$name] as $asset) {
            $path = is_array($asset) ? ($asset['path'] ?? $asset['url'] ?? '') : $asset;
            if (empty($path)) {
                continue;
            }

            $attributes = is_array($asset) ? ($asset['attributes'] ?? []) : [];
            $priority = is_array($asset) ? ($asset['priority'] ?? 10) : 10;
            $external = is_array($asset) && (isset($asset['url']) || ($asset['external'] ?? false));
            $type = is_array($asset) ? ($asset['type'] ?? null) : null;

            // Determine type from extension or explicit type
            if ($type === null) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $type = $ext;
            }

            if ($type === 'css') {
                if ($external) {
                    self::enqueueExternalCss($path, $attributes, $priority);
                } else {
                    self::enqueueCss($path, $attributes, $priority);
                }
            } elseif ($type === 'js') {
                $inHead = is_array($asset) && ($asset['head'] ?? false);
                if ($external) {
                    self::enqueueExternalJs($path, $attributes, $inHead, $priority);
                } else {
                    self::enqueueJs($path, $attributes, $inHead, $priority);
                }
            }
        }
    }

    /**
     * Load multiple collections at once
     */
    public static function loadMultiple(array $names): void
    {
        foreach ($names as $name) {
            self::load($name);
        }
    }

    /**
     * Generate a data URI for small assets (inline embedding)
     */
    public static function dataUri(string $path): ?string
    {
        $fullPath = self::resolvePath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        // Limit size for data URIs (8KB recommended max)
        if (filesize($fullPath) > 8192) {
            return self::url($path);
        }

        $mime = mime_content_type($fullPath);
        $data = base64_encode(file_get_contents($fullPath));

        return "data:{$mime};base64,{$data}";
    }

    /**
     * Get asset version based on configured strategy
     */
    private static function getVersion(string $path): ?string
    {
        switch (self::$config['version_strategy']) {
            case 'timestamp':
                $fullPath = self::resolvePath($path);
                if (file_exists($fullPath)) {
                    return (string) filemtime($fullPath);
                }
                break;

            case 'hash':
                $fullPath = self::resolvePath($path);
                if (file_exists($fullPath)) {
                    return substr(md5_file($fullPath), 0, 8);
                }
                break;

            case 'manifest':
                // Version is already in manifest filename
                return null;

            case 'global':
                return self::$config['global_version'];

            case 'none':
                return null;
        }

        return self::$config['global_version'];
    }

    /**
     * Get integrity hash for an asset
     */
    private static function getIntegrity(string $path): ?string
    {
        if (isset(self::$integrityCache[$path])) {
            return self::$integrityCache[$path];
        }

        // Check manifest for integrity
        if (!empty(self::$manifest)) {
            $cleanPath = self::normalizePath($path);
            if (isset(self::$manifest[$cleanPath]['integrity'])) {
                return self::$integrityCache[$path] = self::$manifest[$cleanPath]['integrity'];
            }
        }

        // Generate integrity hash
        $fullPath = self::resolvePath($path);
        if (file_exists($fullPath)) {
            $hash = base64_encode(hash_file('sha384', $fullPath, true));
            return self::$integrityCache[$path] = 'sha384-' . $hash;
        }

        return null;
    }

    /**
     * Resolve asset path from manifest
     */
    private static function resolveFromManifest(string $path): string
    {
        // Try direct lookup
        if (isset(self::$manifest[$path])) {
            $entry = self::$manifest[$path];
            return is_array($entry) ? ($entry['file'] ?? $path) : $entry;
        }

        // Try with various prefixes (Vite style)
        $prefixes = ['', 'assets/', 'src/', 'resources/'];
        foreach ($prefixes as $prefix) {
            $key = $prefix . $path;
            if (isset(self::$manifest[$key])) {
                $entry = self::$manifest[$key];
                return is_array($entry) ? ($entry['file'] ?? $path) : $entry;
            }
        }

        return $path;
    }

    /**
     * Get base URL for assets
     */
    private static function getBaseUrl(): string
    {
        if (self::$config['base_url'] !== null) {
            return self::$config['base_url'];
        }

        // Auto-detect from request
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        // Fallback to env
        return rtrim($_ENV['APP_URL'] ?? '', '/');
    }

    /**
     * Resolve full filesystem path for an asset
     */
    private static function resolvePath(string $path): string
    {
        $basePath = self::$config['base_path']
            ?? (defined('PUBLIC_PATH') ? PUBLIC_PATH : getcwd());

        return $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    }

    /**
     * Build HTML attributes string
     */
    private static function buildAttributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            } else {
                $parts[] = htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Clear all queued assets (useful for testing or page transitions)
     */
    public static function clear(): void
    {
        self::$queued = [
            'css' => [],
            'js' => [],
            'js_head' => [],
            'preload' => [],
        ];
        self::$inline = [
            'css' => '',
            'js' => '',
            'js_head' => '',
        ];
        self::$cssRendered = false;
        self::$jsRendered = false;
    }

    /**
     * Reset all state including cache (for testing)
     */
    public static function reset(): void
    {
        self::clear();
        self::$urlCache = [];
        self::$integrityCache = [];
        self::$manifest = [];
        self::$collections = [];
        self::$externals = [];
    }

    /**
     * Check if a collection exists
     */
    public static function hasCollection(string $name): bool
    {
        return isset(self::$collections[$name]);
    }

    /**
     * Get all registered collections
     */
    public static function getCollections(): array
    {
        return self::$collections;
    }

    /**
     * Get CDN URL if configured
     */
    public static function getCdnUrl(): ?string
    {
        return self::$config['cdn_enabled'] ? self::$config['cdn_url'] : null;
    }

    /**
     * Check if a CSS file is queued
     */
    public static function hasCss(string $path): bool
    {
        return isset(self::$queued['css'][$path]);
    }

    /**
     * Check if a JS file is queued
     */
    public static function hasJs(string $path): bool
    {
        return isset(self::$queued['js'][$path]) || isset(self::$queued['js_head'][$path]);
    }

    /**
     * Remove a CSS file from the queue
     */
    public static function dequeueCss(string $path): void
    {
        unset(self::$queued['css'][$path]);
    }

    /**
     * Remove a JS file from the queue
     */
    public static function dequeueJs(string $path): void
    {
        unset(self::$queued['js'][$path], self::$queued['js_head'][$path]);
    }
}
