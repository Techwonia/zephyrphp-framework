<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * Security Headers Manager
 *
 * Manages HTTP security headers including Content Security Policy (CSP),
 * with support for nonces, SRI, report-only mode, and modern isolation headers.
 *
 * Features:
 * - Content Security Policy with nonce support
 * - CSP Report-Only mode for monitoring
 * - Violation reporting endpoint
 * - Modern headers: COEP, COOP, CORP
 * - HSTS with preload support
 * - Configurable per-directive CSP sources
 */
class Headers
{
    /** @var array<string, string> Headers to send */
    private static array $headers = [];

    /** @var array<string, array<string>> Custom CSP directive sources */
    private static array $cspDirectives = [];

    /** @var bool Whether to use Report-Only mode */
    private static bool $reportOnly = false;

    /** @var string|null CSP violation report URI */
    private static ?string $reportUri = null;

    /** @var bool Whether to use nonces in CSP */
    private static bool $useNonces = true;

    /** @var bool Whether to include modern isolation headers */
    private static bool $useIsolationHeaders = false;

    /** @var string CSP enforcement level: 'strict', 'moderate', 'relaxed' */
    private static string $cspLevel = 'moderate';

    /**
     * Apply all security headers
     */
    public static function apply(): void
    {
        $isProduction = ($_ENV['ENV'] ?? 'dev') === 'production';

        // Basic security headers
        self::set('X-Content-Type-Options', 'nosniff');
        self::set('X-Frame-Options', 'SAMEORIGIN');
        self::set('X-XSS-Protection', '1; mode=block');
        self::set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy
        self::set('Permissions-Policy', self::getPermissionsPolicy());

        // Content Security Policy
        $cspEnabled = filter_var($_ENV['CSP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($cspEnabled) {
            self::applyCSP($isProduction);
        }

        // HSTS (only in production with HTTPS)
        if ($isProduction && self::isHttps()) {
            $hstsPreload = filter_var($_ENV['HSTS_PRELOAD'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $maxAge = (int) ($_ENV['HSTS_MAX_AGE'] ?? 31536000);
            self::setHSTS($maxAge, true, $hstsPreload);
        }

        // Modern isolation headers (opt-in)
        if (self::$useIsolationHeaders || filter_var($_ENV['USE_ISOLATION_HEADERS'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            self::applyIsolationHeaders();
        }

        // Remove PHP version header
        if (function_exists('header_remove') && !headers_sent()) {
            header_remove('X-Powered-By');
        }

        // Send all headers
        self::send();
    }

    /**
     * Apply Content Security Policy headers
     */
    private static function applyCSP(bool $isProduction): void
    {
        $csp = $isProduction ? self::getProductionCSP() : self::getDevelopmentCSP();

        // Add report URI if configured
        if (self::$reportUri !== null) {
            $csp .= "; report-uri " . self::$reportUri;
            $csp .= "; report-to csp-endpoint";

            // Add Report-To header for modern browsers
            self::set('Report-To', json_encode([
                'group' => 'csp-endpoint',
                'max_age' => 86400,
                'endpoints' => [['url' => self::$reportUri]],
            ]));
        }

        $headerName = self::$reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        self::set($headerName, $csp);
    }

    /**
     * Apply modern isolation headers (COEP, COOP, CORP)
     *
     * These headers enable cross-origin isolation for SharedArrayBuffer
     * and other powerful features, but may break some third-party resources.
     */
    private static function applyIsolationHeaders(): void
    {
        // Cross-Origin-Embedder-Policy
        self::set('Cross-Origin-Embedder-Policy', $_ENV['COEP'] ?? 'credentialless');

        // Cross-Origin-Opener-Policy
        self::set('Cross-Origin-Opener-Policy', $_ENV['COOP'] ?? 'same-origin');

        // Cross-Origin-Resource-Policy
        self::set('Cross-Origin-Resource-Policy', $_ENV['CORP'] ?? 'same-origin');
    }

    /**
     * Get Permissions Policy header value
     */
    private static function getPermissionsPolicy(): string
    {
        $policies = [
            'accelerometer' => '()',
            'autoplay' => '()',
            'camera' => '()',
            'cross-origin-isolated' => '()',
            'display-capture' => '()',
            'encrypted-media' => '()',
            'fullscreen' => '(self)',
            'geolocation' => '()',
            'gyroscope' => '()',
            'keyboard-map' => '()',
            'magnetometer' => '()',
            'microphone' => '()',
            'midi' => '()',
            'payment' => '()',
            'picture-in-picture' => '()',
            'publickey-credentials-get' => '()',
            'screen-wake-lock' => '()',
            'sync-xhr' => '()',
            'usb' => '()',
            'xr-spatial-tracking' => '()',
        ];

        // Allow customization via environment
        $customPolicy = $_ENV['PERMISSIONS_POLICY'] ?? null;
        if ($customPolicy) {
            return $customPolicy;
        }

        $parts = [];
        foreach ($policies as $feature => $value) {
            $parts[] = "{$feature}={$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Get production CSP (strict by default)
     */
    private static function getProductionCSP(): string
    {
        $nonce = self::$useNonces ? Nonce::forCsp() : '';
        $styleNonce = self::$useNonces ? Nonce::styleForCsp() : '';
        $appOrigin = self::getAppUrlOrigin();
        $appSrc = $appOrigin ? " {$appOrigin}" : '';

        $directives = [
            'default-src' => "'self'{$appSrc}" . self::getCustomSources('default-src'),
            'script-src' => "'self'{$appSrc} {$nonce}" . self::getCustomSources('script-src'),
            'script-src-elem' => "'self'{$appSrc} {$nonce}" . self::getCustomSources('script-src-elem'),
            'script-src-attr' => "'none'",
            'style-src' => "'self'{$appSrc} {$styleNonce} https://fonts.googleapis.com" . self::getCustomSources('style-src'),
            'style-src-elem' => "'self'{$appSrc} {$styleNonce} https://fonts.googleapis.com" . self::getCustomSources('style-src-elem'),
            'style-src-attr' => "'unsafe-inline'",
            'img-src' => "'self'{$appSrc} data: https:" . self::getCustomSources('img-src'),
            'font-src' => "'self'{$appSrc} https://fonts.gstatic.com data:" . self::getCustomSources('font-src'),
            'connect-src' => "'self'{$appSrc}" . self::getCustomSources('connect-src'),
            'media-src' => "'self'{$appSrc}" . self::getCustomSources('media-src'),
            'object-src' => "'none'",
            'child-src' => "'self'{$appSrc}" . self::getCustomSources('child-src'),
            'frame-src' => "'self'{$appSrc}" . self::getCustomSources('frame-src'),
            'worker-src' => "'self'{$appSrc} blob:" . self::getCustomSources('worker-src'),
            'manifest-src' => "'self'{$appSrc}" . self::getCustomSources('manifest-src'),
            'base-uri' => "'self'",
            'form-action' => "'self'{$appSrc}" . self::getCustomSources('form-action'),
            'frame-ancestors' => "'self'",
            'upgrade-insecure-requests' => '',
            'block-all-mixed-content' => '',
        ];

        // Apply CSP level adjustments
        if (self::$cspLevel === 'strict') {
            $directives['style-src-attr'] = "'none'";
            $directives['script-src'] = "'self'{$appSrc} 'strict-dynamic' {$nonce}" . self::getCustomSources('script-src');
        } elseif (self::$cspLevel === 'relaxed') {
            $directives['style-src'] = "'self'{$appSrc} 'unsafe-inline' https://fonts.googleapis.com" . self::getCustomSources('style-src');
            $directives['style-src-elem'] = "'self'{$appSrc} 'unsafe-inline' https://fonts.googleapis.com" . self::getCustomSources('style-src-elem');
        }

        return self::buildCSP($directives);
    }

    /**
     * Get development CSP (permissive for easier development)
     */
    private static function getDevelopmentCSP(): string
    {
        $nonce = self::$useNonces ? Nonce::forCsp() : '';
        $appOrigin = self::getAppUrlOrigin();
        $appSrc = $appOrigin ? " {$appOrigin}" : '';

        // Common CDN domains allowed by default
        $cdnDomains = 'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com https://esm.sh';

        $directives = [
            'default-src' => "'self'{$appSrc}" . self::getCustomSources('default-src'),
            'script-src' => "'self'{$appSrc} 'unsafe-inline' 'unsafe-eval' {$nonce} {$cdnDomains}" . self::getCustomSources('script-src'),
            'style-src' => "'self'{$appSrc} 'unsafe-inline' https://fonts.googleapis.com {$cdnDomains}" . self::getCustomSources('style-src'),
            'style-src-elem' => "'self'{$appSrc} 'unsafe-inline' https://fonts.googleapis.com {$cdnDomains}" . self::getCustomSources('style-src-elem'),
            'img-src' => "'self'{$appSrc} data: blob: https: http:" . self::getCustomSources('img-src'),
            'font-src' => "'self'{$appSrc} https://fonts.gstatic.com data: {$cdnDomains}" . self::getCustomSources('font-src'),
            'connect-src' => "'self'{$appSrc} ws: wss: http: https:" . self::getCustomSources('connect-src'),
            'frame-ancestors' => "'self'",
            'form-action' => "'self'{$appSrc}" . self::getCustomSources('form-action'),
        ];

        return self::buildCSP($directives);
    }

    /**
     * Build CSP string from directives array
     */
    private static function buildCSP(array $directives): string
    {
        $parts = [];
        foreach ($directives as $directive => $value) {
            $value = trim($value);
            if ($value === '') {
                $parts[] = $directive;
            } else {
                $parts[] = "{$directive} {$value}";
            }
        }
        return implode('; ', $parts);
    }

    /**
     * Add custom sources to a CSP directive
     */
    public static function addCspSource(string $directive, string|array $sources): void
    {
        if (!isset(self::$cspDirectives[$directive])) {
            self::$cspDirectives[$directive] = [];
        }

        $sources = is_array($sources) ? $sources : [$sources];
        self::$cspDirectives[$directive] = array_merge(self::$cspDirectives[$directive], $sources);
    }

    /**
     * Get the APP_URL origin for CSP whitelisting
     *
     * When APP_URL is configured, its origin is automatically added to CSP
     * sources so that assets served with absolute URLs are not blocked.
     */
    private static function getAppUrlOrigin(): string
    {
        $appUrl = $_ENV['APP_URL'] ?? '';
        if (empty($appUrl)) {
            return '';
        }

        $parsed = parse_url($appUrl);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return '';
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }

        return $origin;
    }

    /**
     * Get custom CSP sources for a directive
     */
    private static function getCustomSources(string $directive): string
    {
        $envKey = 'CSP_' . strtoupper(str_replace('-', '_', $directive));
        $envSources = $_ENV[$envKey] ?? '';

        $sources = [];

        if (!empty($envSources)) {
            $sources = array_map('trim', explode(',', $envSources));
        }

        if (isset(self::$cspDirectives[$directive])) {
            $sources = array_merge($sources, self::$cspDirectives[$directive]);
        }

        return !empty($sources) ? ' ' . implode(' ', array_unique($sources)) : '';
    }

    public static function set(string $name, string $value): void
    {
        self::$headers[$name] = $value;
    }

    public static function remove(string $name): void
    {
        unset(self::$headers[$name]);
    }

    public static function get(string $name): ?string
    {
        return self::$headers[$name] ?? null;
    }

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach (self::$headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    /**
     * Enable CSP Report-Only mode
     */
    public static function reportOnly(bool $enabled = true): void
    {
        self::$reportOnly = $enabled;
    }

    /**
     * Set the CSP violation report URI
     */
    public static function setReportUri(string $uri): void
    {
        self::$reportUri = $uri;
    }

    /**
     * Enable or disable nonces in CSP
     */
    public static function useNonces(bool $enabled = true): void
    {
        self::$useNonces = $enabled;
    }

    /**
     * Enable modern isolation headers (COEP, COOP, CORP)
     */
    public static function useIsolationHeaders(bool $enabled = true): void
    {
        self::$useIsolationHeaders = $enabled;
    }

    /**
     * Set CSP enforcement level: 'strict', 'moderate', or 'relaxed'
     */
    public static function setCspLevel(string $level): void
    {
        if (in_array($level, ['strict', 'moderate', 'relaxed'])) {
            self::$cspLevel = $level;
        }
    }

    /**
     * Set HSTS header
     */
    public static function setHSTS(int $maxAge = 31536000, bool $includeSubdomains = true, bool $preload = false): void
    {
        $value = "max-age={$maxAge}";

        if ($includeSubdomains) {
            $value .= '; includeSubDomains';
        }

        if ($preload) {
            $value .= '; preload';
        }

        self::set('Strict-Transport-Security', $value);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    public static function noCacheHeaders(): void
    {
        self::set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        self::set('Pragma', 'no-cache');
        self::set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
    }

    public static function cacheHeaders(int $seconds = 3600, bool $public = true): void
    {
        $visibility = $public ? 'public' : 'private';
        self::set('Cache-Control', "{$visibility}, max-age={$seconds}");
        self::set('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    public static function corsHeaders(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
        bool $allowCredentials = false,
        int $maxAge = 86400
    ): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

        if (in_array('*', $allowedOrigins)) {
            self::set('Access-Control-Allow-Origin', '*');
        } elseif (in_array($origin, $allowedOrigins)) {
            self::set('Access-Control-Allow-Origin', $origin);
            self::set('Vary', 'Origin');
        }

        self::set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        self::set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));
        self::set('Access-Control-Max-Age', (string) $maxAge);

        if ($allowCredentials) {
            self::set('Access-Control-Allow-Credentials', 'true');
        }
    }

    public static function disableCSP(): void
    {
        self::remove('Content-Security-Policy');
        self::remove('Content-Security-Policy-Report-Only');
    }

    public static function getNonce(): string
    {
        return Nonce::generate();
    }

    public static function getStyleNonce(): string
    {
        return Nonce::style();
    }

    public static function reset(): void
    {
        self::$headers = [];
        self::$cspDirectives = [];
        self::$reportOnly = false;
        self::$reportUri = null;
        self::$useNonces = true;
        self::$useIsolationHeaders = false;
        self::$cspLevel = 'moderate';
        Nonce::reset();
    }
}
