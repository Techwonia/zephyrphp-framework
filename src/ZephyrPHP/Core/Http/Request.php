<?php

declare(strict_types=1);

namespace ZephyrPHP\Core\Http;

use ZephyrPHP\Security\Csrf;
use ZephyrPHP\Security\Sanitizer;

/**
 * Zephyr HTTP Request
 *
 * A comprehensive, user-friendly HTTP request handler with industry-standard features.
 *
 * Features:
 * - Input handling (query, post, JSON, files)
 * - Input casting (boolean, integer, float, date, array)
 * - File uploads with UploadedFile wrapper
 * - Content negotiation (accepts, prefers)
 * - Request fingerprinting
 * - Input validation helpers
 * - Old input flash storage
 * - CSRF validation
 * - Security & sanitization
 *
 * @package ZephyrPHP
 * @author ZephyrPHP Team
 */
class Request
{
    private static ?Request $instance = null;
    private array $get;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private array $headers;
    private ?array $jsonPayload = null;
    private ?array $mergedInput = null;
    private array $routeParameters = [];

    /** @var string[] List of trusted proxy IP addresses */
    private static array $trustedProxies = [];

    /**
     * Set trusted proxy IP addresses
     *
     * Only requests from these IPs will have forwarded headers (X-Forwarded-For, etc.) honored.
     *
     * @param string[] $proxies Array of trusted proxy IP addresses
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    public function __construct()
    {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->files = $this->normalizeFiles($_FILES);
        $this->cookies = $_COOKIE;
        $this->headers = $this->parseHeaders();
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function capture(): self
    {
        return self::getInstance();
    }

    // ========================================================================
    // INPUT RETRIEVAL
    // ========================================================================

    /**
     * Get all input data (merged from all sources)
     */
    public function all(): array
    {
        if ($this->mergedInput === null) {
            $this->mergedInput = array_merge(
                $this->get,
                $this->post,
                $this->getJsonPayload() ?? []
            );
        }
        return $this->mergedInput;
    }

    /**
     * Get an input value from any source
     */
    public function input(string $key, $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Get input value using dot notation
     */
    public function get(string $key, $default = null)
    {
        // Support dot notation for nested values
        if (str_contains($key, '.')) {
            return $this->getDotNotation($this->all(), $key, $default);
        }
        return $this->all()[$key] ?? $default;
    }

    /**
     * Get query string values
     */
    public function query(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->get;
        }
        return $this->get[$key] ?? $default;
    }

    /**
     * Get POST values
     */
    public function post(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    /**
     * Get only specific keys from input
     */
    public function only(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $all = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    /**
     * Get all input except specific keys
     */
    public function except(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Check if input key exists
     */
    public function has(string|array $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $all = $this->all();

        foreach ($keys as $key) {
            if (!array_key_exists($key, $all)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any of the keys exist
     */
    public function hasAny(array $keys): bool
    {
        $all = $this->all();

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if input key exists and is not empty
     */
    public function filled(string|array $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        foreach ($keys as $key) {
            $value = $this->input($key);
            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if input key is missing or empty
     */
    public function missing(string|array $keys): bool
    {
        return !$this->has($keys);
    }

    /**
     * Check if input is empty (not filled)
     */
    public function isNotFilled(string|array $keys): bool
    {
        return !$this->filled($keys);
    }

    /**
     * Get input values with defaults
     */
    public function whenFilled(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->filled($key)) {
            return $callback($this->input($key));
        }

        if ($default) {
            return $default();
        }

        return null;
    }

    /**
     * Get input when has key
     */
    public function whenHas(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->has($key)) {
            return $callback($this->input($key));
        }

        if ($default) {
            return $default();
        }

        return null;
    }

    // ========================================================================
    // INPUT CASTING
    // ========================================================================

    /**
     * Get input as string
     */
    public function string(string $key, string $default = ''): string
    {
        return (string) $this->input($key, $default);
    }

    /**
     * Get input as integer
     */
    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * Get input as float
     */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * Get input as boolean
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        // Handle common boolean representations
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Get input as array
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Try JSON decode
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            // Try comma-separated
            return array_map('trim', explode(',', $value));
        }

        return $default;
    }

    /**
     * Get input as date
     */
    public function date(string $key, ?string $format = null, ?string $timezone = null): ?\DateTime
    {
        $value = $this->input($key);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            $tz = $timezone ? new \DateTimeZone($timezone) : null;

            if ($format) {
                $date = \DateTime::createFromFormat($format, $value, $tz);
                return $date ?: null;
            }

            return new \DateTime($value, $tz);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get input as enum
     */
    public function enum(string $key, string $enumClass, $default = null)
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        if (!enum_exists($enumClass)) {
            return $default;
        }

        // For backed enums
        if (method_exists($enumClass, 'tryFrom')) {
            return $enumClass::tryFrom($value) ?? $default;
        }

        return $default;
    }

    /**
     * Collect specific inputs into a collection-like array
     */
    public function collect(array|string|null $keys = null): array
    {
        if ($keys === null) {
            return $this->all();
        }

        return $this->only(is_array($keys) ? $keys : func_get_args());
    }

    // ========================================================================
    // INPUT TRANSFORMATION
    // ========================================================================

    /**
     * Merge additional data into request
     */
    public function merge(array $data): self
    {
        $this->mergedInput = array_merge($this->all(), $data);
        return $this;
    }

    /**
     * Merge data only if keys don't exist
     */
    public function mergeIfMissing(array $data): self
    {
        $all = $this->all();

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $all)) {
                $all[$key] = $value;
            }
        }

        $this->mergedInput = $all;
        return $this;
    }

    /**
     * Replace all input data
     */
    public function replace(array $data): self
    {
        $this->mergedInput = $data;
        return $this;
    }

    // ========================================================================
    // FILE HANDLING
    // ========================================================================

    /**
     * Get all uploaded files
     */
    public function files(?string $key = null): array
    {
        if ($key === null) {
            return $this->files;
        }
        return $this->files[$key] ?? [];
    }

    /**
     * Get a single uploaded file
     */
    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        if ($file === null || !isset($file['tmp_name']) || $file['tmp_name'] === '') {
            return null;
        }

        return new UploadedFile($file);
    }

    /**
     * Check if file was uploaded
     */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file !== null && $file->isValid();
    }

    /**
     * Get multiple files from a file array input
     */
    public function allFiles(): array
    {
        $files = [];

        foreach ($this->files as $key => $file) {
            if (isset($file['tmp_name'])) {
                if (is_array($file['tmp_name'])) {
                    // Multiple files with same name
                    foreach ($file['tmp_name'] as $index => $tmp) {
                        if ($tmp !== '') {
                            $files[$key][] = new UploadedFile([
                                'name' => $file['name'][$index],
                                'type' => $file['type'][$index],
                                'tmp_name' => $file['tmp_name'][$index],
                                'error' => $file['error'][$index],
                                'size' => $file['size'][$index],
                            ]);
                        }
                    }
                } elseif ($file['tmp_name'] !== '') {
                    $files[$key] = new UploadedFile($file);
                }
            }
        }

        return $files;
    }

    /**
     * Normalize files array structure
     */
    protected function normalizeFiles(array $files): array
    {
        return $files;
    }

    // ========================================================================
    // HEADERS & COOKIES
    // ========================================================================

    /**
     * Get all headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get a header value
     */
    public function header(string $key, $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $key));
        return $this->headers[$key] ?? $default;
    }

    /**
     * Check if header exists
     */
    public function hasHeader(string $key): bool
    {
        $key = strtoupper(str_replace('-', '_', $key));
        return isset($this->headers[$key]);
    }

    /**
     * Get bearer token from Authorization header
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('AUTHORIZATION');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Get basic auth credentials
     */
    public function getUser(): ?string
    {
        return $this->server['PHP_AUTH_USER'] ?? null;
    }

    /**
     * Get basic auth password
     */
    public function getPassword(): ?string
    {
        return $this->server['PHP_AUTH_PW'] ?? null;
    }

    /**
     * Get a cookie value
     */
    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Check if cookie exists
     */
    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    /**
     * Get all cookies
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Parse headers from $_SERVER
     */
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        // Add Content-Type and Content-Length if present
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['CONTENT_TYPE'] = $this->server['CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['CONTENT_LENGTH'] = $this->server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    // ========================================================================
    // HTTP METHOD
    // ========================================================================

    /**
     * Get HTTP method
     */
    public function method(): string
    {
        return $this->getMethod();
    }

    public function getMethod(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        // Handle method override (only from POST requests)
        if ($method === 'POST') {
            $override = $this->header('X-HTTP-METHOD-OVERRIDE')
                ?? $this->input('_method');

            if ($override) {
                $overrideUpper = strtoupper($override);
                $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

                // Only accept valid HTTP methods; ignore anything else
                if (in_array($overrideUpper, $allowed, true)) {
                    $method = $overrideUpper;
                }
            }
        }

        return $method;
    }

    /**
     * Get real HTTP method (before override)
     */
    public function getRealMethod(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Check if method matches
     */
    public function isMethod(string $method): bool
    {
        return $this->getMethod() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    public function isPut(): bool
    {
        return $this->isMethod('PUT');
    }

    public function isPatch(): bool
    {
        return $this->isMethod('PATCH');
    }

    public function isDelete(): bool
    {
        return $this->isMethod('DELETE');
    }

    public function isOptions(): bool
    {
        return $this->isMethod('OPTIONS');
    }

    public function isHead(): bool
    {
        return $this->isMethod('HEAD');
    }

    // ========================================================================
    // URL & PATH
    // ========================================================================

    /**
     * Get the request URI
     */
    public function url(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * Get URL without query string
     */
    public function path(): string
    {
        return parse_url($this->url(), PHP_URL_PATH) ?? '/';
    }

    /**
     * Get decoded path
     */
    public function decodedPath(): string
    {
        return urldecode($this->path());
    }

    /**
     * Get the full URL
     */
    public function fullUrl(): string
    {
        return $this->root() . $this->url();
    }

    /**
     * Get full URL with query string
     */
    public function fullUrlWithQuery(array $query): string
    {
        $currentQuery = $this->query();
        $merged = array_merge($currentQuery, $query);

        $url = $this->root() . $this->path();

        if (!empty($merged)) {
            $url .= '?' . http_build_query($merged);
        }

        return $url;
    }

    /**
     * Get full URL without specific query keys
     */
    public function fullUrlWithoutQuery(array $keys): string
    {
        $query = array_diff_key($this->query(), array_flip($keys));

        $url = $this->root() . $this->path();

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Get root URL (scheme + host)
     */
    public function root(): string
    {
        return $this->getScheme() . '://' . $this->getHost();
    }

    /**
     * Get scheme (http or https)
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * Get host
     */
    public function getHost(): string
    {
        return $this->server['HTTP_HOST']
            ?? $this->server['SERVER_NAME']
            ?? 'localhost';
    }

    /**
     * Get port
     */
    public function getPort(): int
    {
        return (int) ($this->server['SERVER_PORT'] ?? 80);
    }

    /**
     * Check if HTTPS
     */
    public function isSecure(): bool
    {
        if (isset($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }

        // Only trust X-Forwarded-Proto if the request comes from a trusted proxy
        if (!empty(self::$trustedProxies)) {
            $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';
            if (in_array($remoteAddr, self::$trustedProxies, true)) {
                $forwardedProto = $this->header('X-FORWARDED-PROTO');
                if ($forwardedProto === 'https') {
                    return true;
                }
            }
        }

        return ($this->server['SERVER_PORT'] ?? 80) == 443;
    }

    /**
     * Get path segments
     */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', trim($this->path(), '/'))));
    }

    /**
     * Get a specific path segment (1-indexed)
     */
    public function segment(int $index, $default = null): ?string
    {
        $segments = $this->segments();
        return $segments[$index - 1] ?? $default;
    }

    /**
     * Check if path matches pattern
     */
    public function is(string ...$patterns): bool
    {
        $path = $this->decodedPath();

        foreach ($patterns as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            // Support wildcards
            $pattern = preg_quote($pattern, '#');
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '$#', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route name matches
     */
    public function routeIs(string ...$patterns): bool
    {
        return \ZephyrPHP\Router\Route::is(...$patterns);
    }

    /**
     * Get previous URL (referer)
     */
    public function previousUrl(): ?string
    {
        return $this->header('REFERER');
    }

    // ========================================================================
    // JSON
    // ========================================================================

    /**
     * Get JSON payload
     */
    public function getJsonPayload(): ?array
    {
        if ($this->jsonPayload === null) {
            $input = file_get_contents('php://input');
            $this->jsonPayload = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->jsonPayload = null;
            }
        }
        return $this->jsonPayload;
    }

    /**
     * Get JSON value
     */
    public function json(?string $key = null, $default = null)
    {
        $payload = $this->getJsonPayload();

        if ($key === null) {
            return $payload;
        }

        if ($payload === null) {
            return $default;
        }

        return $this->getDotNotation($payload, $key, $default);
    }

    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->header('CONTENT_TYPE') ?? '';
        return str_contains($contentType, '/json') || str_contains($contentType, '+json');
    }

    /**
     * Check if request expects JSON response
     */
    public function expectsJson(): bool
    {
        return $this->wantsJson();
    }

    /**
     * Check if request wants JSON response
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('ACCEPT') ?? '';
        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    // ========================================================================
    // CONTENT NEGOTIATION
    // ========================================================================

    /**
     * Get accepted content types
     */
    public function getAcceptableContentTypes(): array
    {
        $accept = $this->header('ACCEPT') ?? '*/*';
        return $this->parseAcceptHeader($accept);
    }

    /**
     * Check if content type is accepted
     */
    public function accepts(string|array $contentTypes): bool
    {
        $acceptable = $this->getAcceptableContentTypes();
        $contentTypes = is_array($contentTypes) ? $contentTypes : [$contentTypes];

        foreach ($acceptable as $type) {
            if ($type === '*/*') {
                return true;
            }

            foreach ($contentTypes as $check) {
                if ($type === $check || $this->matchesType($type, $check)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if request prefers JSON
     */
    public function prefersJson(): bool
    {
        $acceptable = $this->getAcceptableContentTypes();
        $first = $acceptable[0] ?? '*/*';

        return str_contains($first, 'json');
    }

    /**
     * Check if request prefers HTML
     */
    public function prefersHtml(): bool
    {
        $acceptable = $this->getAcceptableContentTypes();
        $first = $acceptable[0] ?? '*/*';

        return str_contains($first, 'html');
    }

    /**
     * Get preferred format
     */
    public function format(string $default = 'html'): string
    {
        $acceptable = $this->getAcceptableContentTypes();

        foreach ($acceptable as $type) {
            if (str_contains($type, 'html')) return 'html';
            if (str_contains($type, 'json')) return 'json';
            if (str_contains($type, 'xml')) return 'xml';
            if (str_contains($type, 'text')) return 'txt';
        }

        return $default;
    }

    /**
     * Parse accept header into sorted array
     */
    protected function parseAcceptHeader(string $header): array
    {
        $types = [];
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $part = trim($part);
            $quality = 1.0;

            if (str_contains($part, ';q=')) {
                [$part, $q] = explode(';q=', $part);
                $quality = (float) $q;
            }

            $types[$part] = $quality;
        }

        arsort($types);
        return array_keys($types);
    }

    /**
     * Check if MIME types match
     */
    protected function matchesType(string $actual, string $type): bool
    {
        if ($actual === $type) return true;

        [$actualMain, $actualSub] = explode('/', $actual) + ['*', '*'];
        [$typeMain, $typeSub] = explode('/', $type) + ['*', '*'];

        if ($actualMain === '*' || $typeMain === '*') {
            return true;
        }

        if ($actualMain !== $typeMain) {
            return false;
        }

        return $actualSub === '*' || $typeSub === '*' || $actualSub === $typeSub;
    }

    // ========================================================================
    // AJAX & SPECIAL REQUESTS
    // ========================================================================

    /**
     * Check if AJAX request
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('X-REQUESTED-WITH') ?? '') === 'xmlhttprequest';
    }

    /**
     * Alias for isAjax
     */
    public function ajax(): bool
    {
        return $this->isAjax();
    }

    /**
     * Check if PJAX request
     */
    public function isPjax(): bool
    {
        return $this->header('X-PJAX') !== null;
    }

    /**
     * Check if prefetch request
     */
    public function isPrefetch(): bool
    {
        return $this->header('X-Moz') === 'prefetch'
            || $this->header('Purpose') === 'prefetch';
    }

    // ========================================================================
    // CLIENT INFO
    // ========================================================================

    /**
     * Get client IP address
     */
    public function ip(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust forwarded headers if REMOTE_ADDR is a configured trusted proxy
        if (!empty(self::$trustedProxies) && in_array($remoteAddr, self::$trustedProxies, true)) {
            $forwardedHeaders = [
                'HTTP_CF_CONNECTING_IP',     // Cloudflare
                'HTTP_X_REAL_IP',            // Nginx proxy
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
            ];

            foreach ($forwardedHeaders as $header) {
                if (!empty($this->server[$header])) {
                    $ip = trim(explode(',', $this->server[$header])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        // Fall back to REMOTE_ADDR only
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return '0.0.0.0';
    }

    /**
     * Get all possible IPs (for forwarded requests)
     */
    public function ips(): array
    {
        $forwarded = $this->header('X-FORWARDED-FOR');

        if ($forwarded) {
            return array_map('trim', explode(',', $forwarded));
        }

        return [$this->ip()];
    }

    /**
     * Get user agent
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    // ========================================================================
    // REQUEST FINGERPRINT
    // ========================================================================

    /**
     * Generate a unique fingerprint for this request
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->getMethod(),
            $this->root(),
            $this->path(),
            $this->ip(),
        ]));
    }

    // ========================================================================
    // ROUTE PARAMETERS
    // ========================================================================

    /**
     * Set route parameters
     */
    public function setRouteParameters(array $parameters): self
    {
        $this->routeParameters = $parameters;
        return $this;
    }

    /**
     * Get route parameters
     */
    public function route(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->routeParameters;
        }
        return $this->routeParameters[$key] ?? $default;
    }

    // ========================================================================
    // OLD INPUT (Flash)
    // ========================================================================

    /**
     * Get old input value
     */
    public function old(string $key, $default = null)
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }

    /**
     * Flash current input to session
     *
     * Automatically excludes sensitive fields (passwords, tokens, etc.)
     */
    public function flash(): void
    {
        $except = ['password', 'password_confirmation', 'current_password', 'credit_card', 'cvv', 'ssn', 'token', 'secret'];
        $_SESSION['_old_input'] = array_diff_key($this->all(), array_flip($except));
    }

    /**
     * Flash only specific keys
     */
    public function flashOnly(array $keys): void
    {
        $_SESSION['_old_input'] = $this->only($keys);
    }

    /**
     * Flash all except specific keys
     */
    public function flashExcept(array $keys): void
    {
        $_SESSION['_old_input'] = $this->except($keys);
    }

    /**
     * Flush old input from session
     */
    public function flushOld(): void
    {
        unset($_SESSION['_old_input']);
    }

    // ========================================================================
    // SECURITY
    // ========================================================================

    /**
     * Validate CSRF token
     */
    public function validateCSRFToken(): bool
    {
        $token = $this->input('csrf_token')
            ?? $this->input('_token')
            ?? $this->header('X-CSRF-TOKEN')
            ?? $this->header('X-XSRF-TOKEN');

        return Csrf::validate($token);
    }

    /**
     * Get sanitized input
     */
    public function sanitized(string $key, string $type = 'string', $default = null)
    {
        $value = $this->input($key, $default);

        if ($value === null) {
            return $default;
        }

        return match ($type) {
            'int', 'integer' => Sanitizer::int($value),
            'float', 'double' => Sanitizer::float($value),
            'email' => Sanitizer::email($value),
            'url' => Sanitizer::url($value),
            'alphanumeric' => Sanitizer::alphanumeric($value),
            'slug' => Sanitizer::slug($value),
            default => Sanitizer::string($value),
        };
    }

    // ========================================================================
    // SERVER & ENVIRONMENT
    // ========================================================================

    /**
     * Get server variable
     */
    public function server(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->server;
        }
        return $this->server[$key] ?? $default;
    }

    // ========================================================================
    // UTILITY
    // ========================================================================

    /**
     * Get value using dot notation
     */
    protected function getDotNotation(array $array, string $key, $default = null)
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Convert request to array
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Dump request info for debugging
     */
    public function dump(): array
    {
        return [
            'method' => $this->getMethod(),
            'url' => $this->fullUrl(),
            'path' => $this->path(),
            'query' => $this->query(),
            'post' => $this->post,
            'json' => $this->getJsonPayload(),
            'headers' => $this->headers,
            'cookies' => $this->cookies,
            'files' => array_keys($this->files),
            'ip' => $this->ip(),
            'userAgent' => $this->userAgent(),
        ];
    }
}

/**
 * Uploaded File Handler
 *
 * Provides a clean interface for handling file uploads.
 */
class UploadedFile
{
    private array $file;

    public function __construct(array $file)
    {
        $this->file = $file;
    }

    /**
     * Get original filename
     */
    public function getClientOriginalName(): string
    {
        return $this->file['name'] ?? '';
    }

    /**
     * Get original extension
     */
    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->getClientOriginalName(), PATHINFO_EXTENSION);
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return $this->file['type'] ?? 'application/octet-stream';
    }

    /**
     * Get guessed extension based on MIME
     */
    public function guessExtension(): string
    {
        $mime = $this->getMimeType();
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/css' => 'css',
            'application/json' => 'json',
            'application/xml' => 'xml',
        ];

        return $extensions[$mime] ?? $this->getClientOriginalExtension();
    }

    /**
     * Get file size in bytes
     */
    public function getSize(): int
    {
        return (int) ($this->file['size'] ?? 0);
    }

    /**
     * Get temporary path
     */
    public function getPathname(): string
    {
        return $this->file['tmp_name'] ?? '';
    }

    /**
     * Alias for getPathname
     */
    public function path(): string
    {
        return $this->getPathname();
    }

    /**
     * Get real path
     */
    public function getRealPath(): string|false
    {
        return realpath($this->getPathname());
    }

    /**
     * Get upload error code
     */
    public function getError(): int
    {
        return (int) ($this->file['error'] ?? UPLOAD_ERR_OK);
    }

    /**
     * Get error message
     */
    public function getErrorMessage(): ?string
    {
        $messages = [
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];

        return $messages[$this->getError()] ?? 'Unknown upload error.';
    }

    /**
     * Check if file was uploaded successfully
     */
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK
            && is_uploaded_file($this->getPathname());
    }

    /**
     * Move file to destination
     */
    public function move(string $directory, ?string $name = null): string
    {
        $name = $name ?? $this->getClientOriginalName();
        $destination = rtrim($directory, '/') . '/' . $name;

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!move_uploaded_file($this->getPathname(), $destination)) {
            throw new \RuntimeException("Failed to move uploaded file to {$destination}");
        }

        return $destination;
    }

    /**
     * Store file with generated name
     */
    public function store(string $directory, ?string $disk = null): string
    {
        $name = $this->hashName();
        return $this->move($directory, $name);
    }

    /**
     * Store file with original name
     */
    public function storeAs(string $directory, string $name): string
    {
        return $this->move($directory, $name);
    }

    /**
     * Generate hash name for file
     */
    public function hashName(?string $extension = null): string
    {
        $extension = $extension ?? $this->guessExtension();
        return bin2hex(random_bytes(20)) . '.' . $extension;
    }

    /**
     * Get file contents
     */
    public function getContent(): string
    {
        return file_get_contents($this->getPathname());
    }

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->getMimeType(), 'image/');
    }

    /**
     * Get image dimensions (if image)
     */
    public function dimensions(): ?array
    {
        if (!$this->isImage()) {
            return null;
        }

        $info = getimagesize($this->getPathname());

        if (!$info) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
        ];
    }
}
