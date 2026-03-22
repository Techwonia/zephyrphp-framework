<?php

declare(strict_types=1);

namespace ZephyrPHP\Core\Http;

/**
 * Zephyr HTTP Response
 *
 * A comprehensive, user-friendly HTTP response handler with industry-standard features.
 *
 * Features:
 * - Multiple response types (HTML, JSON, XML, Text, File)
 * - Fluent chainable interface
 * - CORS support
 * - Caching headers (Cache-Control, ETag, Last-Modified)
 * - Cookie management
 * - File downloads and streaming
 * - Redirect helpers
 * - JSON API format helpers
 * - Compression support
 *
 * @package ZephyrPHP
 * @author ZephyrPHP Team
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $content = '';
    private array $cookies = [];
    private ?string $charset = 'utf-8';
    private bool $headersSent = false;

    private const STATUS_TEXTS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        419 => 'Page Expired',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    // ========================================================================
    // CONSTRUCTORS
    // ========================================================================

    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Create a new response instance
     */
    public static function make(string $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    /**
     * Create a new response builder
     */
    public static function create(string $content = '', int $statusCode = 200): ResponseBuilder
    {
        return new ResponseBuilder($content, $statusCode);
    }

    // ========================================================================
    // STATUS CODE
    // ========================================================================

    /**
     * Set status code
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Get status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Alias for setStatusCode
     */
    public function status(int $statusCode): self
    {
        return $this->setStatusCode($statusCode);
    }

    /**
     * Get status text
     */
    public function getStatusText(): string
    {
        return self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';
    }

    // ========================================================================
    // HEADERS
    // ========================================================================

    /**
     * Set a header
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Alias for setHeader
     */
    public function header(string $name, string $value): self
    {
        return $this->setHeader($name, $value);
    }

    /**
     * Get a header value
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if header exists
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Remove a header
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * Set multiple headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
        return $this;
    }

    // ========================================================================
    // CONTENT
    // ========================================================================

    /**
     * Set content
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Alias for setContent
     */
    public function content(string $content): self
    {
        return $this->setContent($content);
    }

    /**
     * Get content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set charset
     */
    public function setCharset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Get charset
     */
    public function getCharset(): ?string
    {
        return $this->charset;
    }

    // ========================================================================
    // SEND RESPONSE
    // ========================================================================

    /**
     * Send the response
     */
    public function send(): static
    {
        $this->sendHeaders();
        $this->sendCookies();
        $this->sendContent();
        return $this;
    }

    /**
     * Send and exit
     */
    public function sendAndExit(): never
    {
        $this->send();
        exit;
    }

    /**
     * Send headers
     */
    protected function sendHeaders(): void
    {
        if (headers_sent() || $this->headersSent) {
            return;
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        $this->headersSent = true;
    }

    /**
     * Send cookies
     */
    protected function sendCookies(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['options']
            );
        }
    }

    /**
     * Send content
     */
    protected function sendContent(): void
    {
        echo $this->content;
    }

    // ========================================================================
    // CONTENT TYPE HELPERS
    // ========================================================================

    /**
     * Create JSON response
     */
    public static function json($data, int $statusCode = 200, int $options = 0): self
    {
        $options = $options ?: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode($data, $options));
        return $response;
    }

    /**
     * Create pretty JSON response (for debugging)
     */
    public static function jsonPretty($data, int $statusCode = 200): self
    {
        return self::json($data, $statusCode, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Create JSONP response
     */
    public static function jsonp($data, string $callback, int $statusCode = 200): self
    {
        // Validate callback name to prevent XSS injection
        if (!preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$.]*$/', $callback)) {
            throw new \InvalidArgumentException('Invalid JSONP callback name.');
        }

        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/javascript; charset=utf-8');
        $response->setContent($callback . '(' . json_encode($data) . ');');
        return $response;
    }

    /**
     * Create HTML response
     */
    public static function html(string $content, int $statusCode = 200): self
    {
        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setContent($content);
        return $response;
    }

    /**
     * Create text response
     */
    public static function text(string $content, int $statusCode = 200): self
    {
        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent($content);
        return $response;
    }

    /**
     * Create XML response
     */
    public static function xml(string $content, int $statusCode = 200): self
    {
        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $response->setContent($content);
        return $response;
    }

    /**
     * Create empty response
     */
    public static function noContent(): self
    {
        return new self('', 204);
    }

    // ========================================================================
    // HTTP STATUS HELPERS
    // ========================================================================

    /**
     * 201 Created response
     */
    public static function created($data = null, ?string $location = null): self
    {
        $response = $data !== null ? self::json($data, 201) : new self('', 201);

        if ($location !== null) {
            $response->setHeader('Location', $location);
        }

        return $response;
    }

    /**
     * 202 Accepted response
     */
    public static function accepted($data = null): self
    {
        return $data !== null ? self::json($data, 202) : new self('', 202);
    }

    /**
     * 400 Bad Request
     */
    public static function badRequest($message = 'Bad Request'): self
    {
        return self::error($message, 400);
    }

    /**
     * 401 Unauthorized
     */
    public static function unauthorized($message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    /**
     * 403 Forbidden
     */
    public static function forbidden($message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    /**
     * 404 Not Found
     */
    public static function notFound($message = 'Not Found'): self
    {
        return self::error($message, 404);
    }

    /**
     * 405 Method Not Allowed
     */
    public static function methodNotAllowed(array $allowedMethods, $message = 'Method Not Allowed'): self
    {
        $response = self::error($message, 405);
        $response->setHeader('Allow', implode(', ', $allowedMethods));
        return $response;
    }

    /**
     * 409 Conflict
     */
    public static function conflict($message = 'Conflict'): self
    {
        return self::error($message, 409);
    }

    /**
     * 410 Gone
     */
    public static function gone($message = 'Gone'): self
    {
        return self::error($message, 410);
    }

    /**
     * 422 Unprocessable Entity (Validation Error)
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return self::json([
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /**
     * 429 Too Many Requests
     */
    public static function tooManyRequests(?int $retryAfter = null, $message = 'Too Many Requests'): self
    {
        $response = self::error($message, 429);

        if ($retryAfter !== null) {
            $response->setHeader('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * 500 Server Error
     */
    public static function serverError($message = 'Internal Server Error'): self
    {
        return self::error($message, 500);
    }

    /**
     * 503 Service Unavailable
     */
    public static function serviceUnavailable(?int $retryAfter = null, $message = 'Service Unavailable'): self
    {
        $response = self::error($message, 503);

        if ($retryAfter !== null) {
            $response->setHeader('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * Generic error response
     */
    public static function error($message, int $statusCode = 500): self
    {
        $data = is_array($message) ? $message : ['error' => $message];
        return self::json($data, $statusCode);
    }

    // ========================================================================
    // JSON API HELPERS
    // ========================================================================

    /**
     * JSON API success response
     */
    public static function success($data = null, string $message = 'Success', int $statusCode = 200): self
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return self::json($payload, $statusCode);
    }

    /**
     * JSON API failure response
     */
    public static function fail($data = null, string $message = 'Failed', int $statusCode = 400): self
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['errors'] = $data;
        }

        return self::json($payload, $statusCode);
    }

    /**
     * Paginated JSON response
     */
    public static function paginated(
        array $data,
        int $total,
        int $perPage,
        int $currentPage,
        int $statusCode = 200
    ): self {
        $lastPage = (int) ceil($total / $perPage);

        return self::json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'from' => ($currentPage - 1) * $perPage + 1,
                'to' => min($currentPage * $perPage, $total),
            ],
        ], $statusCode);
    }

    // ========================================================================
    // REDIRECT HELPERS
    // ========================================================================

    /**
     * Redirect to URL
     */
    public function redirect(string $url, int $statusCode = 302): never
    {
        $this->setHeader('Location', $url);
        $this->setStatusCode($statusCode);
        $this->sendAndExit();
    }

    /**
     * Redirect to URL (static)
     */
    public static function redirectTo(string $url, int $statusCode = 302): self
    {
        self::validateRedirectUrl($url);
        $response = new self('', $statusCode);
        $response->setHeader('Location', $url);
        return $response;
    }

    /**
     * Permanent redirect (301)
     */
    public static function permanentRedirect(string $url): self
    {
        return self::redirectTo($url, 301);
    }

    /**
     * Temporary redirect (307)
     */
    public static function temporaryRedirect(string $url): self
    {
        return self::redirectTo($url, 307);
    }

    /**
     * Redirect back to previous page
     *
     * Only redirects to same-origin referer to prevent open redirect attacks.
     */
    public function back(string $fallback = '/'): never
    {
        $url = $fallback;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        if ($referer !== null) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $currentHost = $_SERVER['HTTP_HOST'] ?? '';

            // Strip port from HTTP_HOST for comparison
            $currentHost = strtolower(explode(':', $currentHost)[0]);
            $refererHost = strtolower((string) $refererHost);

            if ($refererHost === $currentHost) {
                $url = $referer;
            }
        }

        $this->redirect($url);
    }

    /**
     * Redirect to named route
     */
    public static function route(string $name, array $parameters = [], int $statusCode = 302): self
    {
        $url = route($name, $parameters);
        return self::redirectTo($url, $statusCode);
    }

    /**
     * Validate a URL for safe redirection.
     *
     * Blocks javascript:, data:, vbscript:, and protocol-relative URLs (//evil.com).
     * Only http and https schemes are allowed.
     *
     * @throws \InvalidArgumentException If the URL uses a disallowed scheme.
     */
    private static function validateRedirectUrl(string $url): void
    {
        $url = trim($url);

        // Decode percent-encoded characters to catch bypass attempts like %2f%2f
        $decoded = rawurldecode($url);

        // Block protocol-relative URLs (//evil.com) including encoded variants
        if (preg_match('#^//[^/]#', $url) || preg_match('#^//[^/]#', $decoded)) {
            throw new \InvalidArgumentException('Protocol-relative redirect URLs are not allowed.');
        }

        // Parse the scheme; only allow http(s) or relative paths (no scheme)
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null) {
            $scheme = strtolower($scheme);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException("Redirect URL scheme '{$scheme}' is not allowed.");
            }
        }

        // Also check decoded URL for scheme bypass (e.g. JaVaScRiPt:)
        $decodedScheme = parse_url($decoded, PHP_URL_SCHEME);
        if ($decodedScheme !== null) {
            $decodedScheme = strtolower($decodedScheme);
            if (!in_array($decodedScheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException("Redirect URL scheme '{$decodedScheme}' is not allowed.");
            }
        }
    }

    /**
     * Redirect away (external URL)
     *
     * Only allows http/https URLs. Blocks javascript:, data:, and protocol-relative URLs.
     */
    public static function away(string $url, int $statusCode = 302): self
    {
        self::validateRedirectUrl($url);
        return self::redirectTo($url, $statusCode);
    }

    // ========================================================================
    // FILE RESPONSES
    // ========================================================================

    /**
     * Validate that a file path is within an allowed directory.
     *
     * Prevents path-traversal attacks by resolving the real path
     * and checking it falls within BASE_PATH (or a custom allowed root).
     *
     * @throws \RuntimeException If the path is outside allowed directories.
     */
    private function validateFilePath(string $filePath): string
    {
        // Reject obvious traversal patterns early
        if (str_contains($filePath, '..')) {
            self::forbidden('Access denied: invalid file path.')->sendAndExit();
        }

        $realPath = realpath($filePath);
        if ($realPath === false) {
            self::notFound('File not found')->sendAndExit();
        }

        $allowedRoot = defined('BASE_PATH') ? realpath(BASE_PATH) : null;
        if ($allowedRoot !== false && $allowedRoot !== null) {
            if (!str_starts_with($realPath, $allowedRoot . DIRECTORY_SEPARATOR) && $realPath !== $allowedRoot) {
                self::forbidden('Access denied: file is outside the allowed directory.')->sendAndExit();
            }
        }

        return $realPath;
    }

    /**
     * Download file
     */
    public function download(string $filePath, ?string $name = null, array $headers = []): never
    {
        $filePath = $this->validateFilePath($filePath);

        if (!file_exists($filePath)) {
            self::notFound('File not found')->sendAndExit();
        }

        $name = $name ?? basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $this->setHeader('Content-Type', $mimeType);
        $safeName = str_replace(["\r", "\n", "\0", '"'], ['', '', '', '\\"'], $name);
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"');
        $this->setHeader('Content-Length', (string) filesize($filePath));
        $this->setHeader('Content-Transfer-Encoding', 'binary');
        $this->setHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        $this->setHeader('Pragma', 'no-cache');

        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        $this->sendHeaders();
        $this->sendCookies();
        readfile($filePath);
        exit;
    }

    /**
     * Stream file content directly
     */
    public function streamDownload(callable $callback, string $name, array $headers = []): never
    {
        $this->setHeader('Content-Type', 'application/octet-stream');
        $safeName = str_replace(["\r", "\n", "\0", '"'], ['', '', '', '\\"'], $name);
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"');
        $this->setHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate');

        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        $this->sendHeaders();
        $this->sendCookies();

        $callback();

        exit;
    }

    /**
     * Display file in browser
     */
    public function file(string $filePath, ?string $name = null): never
    {
        $filePath = $this->validateFilePath($filePath);

        if (!file_exists($filePath)) {
            self::notFound('File not found')->sendAndExit();
        }

        $name = $name ?? basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $this->setHeader('Content-Type', $mimeType);
        $this->setHeader('Content-Disposition', 'inline; filename="' . addslashes($name) . '"');
        $this->setHeader('Content-Length', (string) filesize($filePath));

        $this->sendHeaders();
        $this->sendCookies();
        readfile($filePath);
        exit;
    }

    /**
     * Stream response
     */
    public function stream(callable $callback, int $statusCode = 200, array $headers = []): never
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'text/event-stream');
        $this->setHeader('Cache-Control', 'no-cache');
        $this->setHeader('Connection', 'keep-alive');
        $this->setHeader('X-Accel-Buffering', 'no');

        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        $this->sendHeaders();

        // Disable output buffering
        while (ob_get_level()) {
            ob_end_flush();
        }

        $callback();

        exit;
    }

    // ========================================================================
    // COOKIE MANAGEMENT
    // ========================================================================

    /**
     * Add cookie to response
     */
    public function cookie(
        string $name,
        string $value,
        int $minutes = 0,
        ?string $path = '/',
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        // Auto-detect HTTPS when $secure is not explicitly provided
        if ($secure === null) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        }

        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => [
                'expires' => $minutes > 0 ? time() + ($minutes * 60) : 0,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $sameSite,
            ]
        ];

        return $this;
    }

    /**
     * Add forever cookie (5 years)
     */
    public function cookieForever(
        string $name,
        string $value,
        ?string $path = '/',
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true
    ): self {
        return $this->cookie($name, $value, 60 * 24 * 365 * 5, $path, $domain, $secure, $httpOnly);
    }

    /**
     * Remove cookie
     */
    public function withoutCookie(string $name, ?string $path = '/', ?string $domain = null): self
    {
        return $this->cookie($name, '', -2628000, $path, $domain);
    }

    /**
     * Clear all cookies
     */
    public function clearCookies(): self
    {
        $this->cookies = [];
        return $this;
    }

    // ========================================================================
    // CACHING
    // ========================================================================

    /**
     * Set cache headers
     */
    public function cache(int $seconds, bool $public = true): self
    {
        $visibility = $public ? 'public' : 'private';
        $this->setHeader('Cache-Control', "{$visibility}, max-age={$seconds}");
        $this->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
        return $this;
    }

    /**
     * Set no-cache headers
     */
    public function noCache(): self
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'no-cache');
        $this->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        return $this;
    }

    /**
     * Set ETag header
     */
    public function etag(string $etag, bool $weak = false): self
    {
        $prefix = $weak ? 'W/' : '';
        $this->setHeader('ETag', $prefix . '"' . $etag . '"');
        return $this;
    }

    /**
     * Set Last-Modified header
     */
    public function lastModified(\DateTimeInterface $date): self
    {
        $this->setHeader('Last-Modified', $date->format('D, d M Y H:i:s') . ' GMT');
        return $this;
    }

    /**
     * Check if response can use 304 Not Modified
     */
    public function isNotModified(Request $request): bool
    {
        $etag = $this->getHeader('ETag');
        $lastModified = $this->getHeader('Last-Modified');

        $ifNoneMatch = $request->header('If-None-Match');
        $ifModifiedSince = $request->header('If-Modified-Since');

        if ($ifNoneMatch && $etag) {
            return trim($ifNoneMatch, '"') === trim($etag, '"');
        }

        if ($ifModifiedSince && $lastModified) {
            return strtotime($ifModifiedSince) >= strtotime($lastModified);
        }

        return false;
    }

    // ========================================================================
    // CORS
    // ========================================================================

    /**
     * Add CORS headers
     */
    public function withCors(
        string|array $origins = '*',
        array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type', 'Authorization', 'X-Requested-With'],
        bool $credentials = false,
        int $maxAge = 86400
    ): self {
        $originValue = is_array($origins) ? implode(', ', $origins) : $origins;

        $this->setHeader('Access-Control-Allow-Origin', $originValue);
        $this->setHeader('Access-Control-Allow-Methods', implode(', ', $methods));
        $this->setHeader('Access-Control-Allow-Headers', implode(', ', $headers));
        $this->setHeader('Access-Control-Max-Age', (string) $maxAge);

        if ($credentials) {
            $this->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $this;
    }

    /**
     * Handle CORS preflight request
     */
    public static function corsPreflightResponse(
        string|array $origins = '*',
        array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type', 'Authorization', 'X-Requested-With'],
        bool $credentials = false
    ): self {
        return (new self('', 204))
            ->withCors($origins, $methods, $headers, $credentials);
    }

    // ========================================================================
    // SECURITY HEADERS
    // ========================================================================

    /**
     * Add common security headers
     */
    public function withSecurityHeaders(): self
    {
        $this->setHeader('X-Content-Type-Options', 'nosniff');
        $this->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->setHeader('X-XSS-Protection', '0');
        $this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        return $this;
    }

    /**
     * Set Content Security Policy
     */
    public function contentSecurityPolicy(array $directives): self
    {
        $policy = [];

        foreach ($directives as $directive => $values) {
            $valueString = is_array($values) ? implode(' ', $values) : $values;
            $policy[] = "{$directive} {$valueString}";
        }

        $this->setHeader('Content-Security-Policy', implode('; ', $policy));
        return $this;
    }

    /**
     * Set Strict-Transport-Security header
     */
    public function hsts(int $maxAge = 31536000, bool $includeSubdomains = true, bool $preload = false): self
    {
        $value = "max-age={$maxAge}";

        if ($includeSubdomains) {
            $value .= '; includeSubDomains';
        }

        if ($preload) {
            $value .= '; preload';
        }

        $this->setHeader('Strict-Transport-Security', $value);
        return $this;
    }

    // ========================================================================
    // STATUS CHECKS
    // ========================================================================

    /**
     * Check if response is informational (1xx)
     */
    public function isInformational(): bool
    {
        return $this->statusCode >= 100 && $this->statusCode < 200;
    }

    /**
     * Check if response is successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response is redirection (3xx)
     */
    public function isRedirection(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Check if response is client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Check if response is OK (200)
     */
    public function isOk(): bool
    {
        return $this->statusCode === 200;
    }

    /**
     * Check if response is forbidden (403)
     */
    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    /**
     * Check if response is not found (404)
     */
    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    /**
     * Check if response is empty
     */
    public function isEmpty(): bool
    {
        return in_array($this->statusCode, [204, 304]);
    }

    // ========================================================================
    // UTILITY
    // ========================================================================

    /**
     * Convert to string
     */
    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * Prepare response for sending
     */
    public function prepare(Request $request): self
    {
        // Remove content for HEAD requests
        if ($request->isHead()) {
            $this->setContent('');
        }

        // Handle 304 Not Modified
        if ($this->isNotModified($request)) {
            $this->setStatusCode(304);
            $this->setContent('');
        }

        return $this;
    }
}

/**
 * Response Builder
 *
 * Provides a fluent interface for building responses.
 */
class ResponseBuilder
{
    private string $content;
    private int $statusCode;
    private array $headers = [];
    private array $cookies = [];

    public function __construct(string $content = '', int $statusCode = 200)
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
    }

    /**
     * Set content
     */
    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Set status code
     */
    public function status(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Set header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set multiple headers
     */
    public function headers(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Add cookie
     */
    public function cookie(string $name, string $value, int $minutes = 0): self
    {
        $this->cookies[$name] = [
            'value' => $value,
            'minutes' => $minutes,
        ];
        return $this;
    }

    /**
     * Build the response
     */
    public function build(): Response
    {
        $response = new Response($this->content, $this->statusCode, $this->headers);

        foreach ($this->cookies as $name => $data) {
            $response->cookie($name, $data['value'], $data['minutes']);
        }

        return $response;
    }

    /**
     * Build and send
     */
    public function send(): Response
    {
        return $this->build()->send();
    }
}
