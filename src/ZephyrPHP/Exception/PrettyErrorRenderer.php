<?php

declare(strict_types=1);

namespace ZephyrPHP\Exception;

/**
 * Enhanced pretty error renderer for development mode.
 *
 * Provides rich error output with:
 * - Syntax-highlighted code context
 * - Request details (method, URL, headers, body)
 * - Session and cookie data
 * - Server environment info
 * - Exception chain (previous exceptions)
 *
 * Register with the Handler:
 *   Handler::getInstance()->setCustomRenderer([PrettyErrorRenderer::class, 'render']);
 */
class PrettyErrorRenderer
{
    /**
     * Render a pretty error page for development.
     */
    public static function render(\Throwable $e): void
    {
        $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $title = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $codeSnippet = self::getCodeSnippet($e->getFile(), $line);
        $traceHtml = self::formatTrace($e);
        $requestHtml = self::formatRequest();
        $previousHtml = self::formatPreviousExceptions($e);
        $envHtml = self::formatEnvironment();

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #0f0f0f; color: #e0e0e0; line-height: 1.6; font-size: 14px; }
        .header { background: linear-gradient(135deg, #dc2626, #991b1b); color: white; padding: 32px 0; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .header h1 { font-size: 1rem; font-weight: 400; opacity: 0.85; margin-bottom: 8px; font-family: 'Consolas', 'Monaco', monospace; }
        .header .message { font-size: 1.5rem; font-weight: 600; word-break: break-word; }
        .header .location { margin-top: 12px; font-size: 0.85rem; opacity: 0.75; font-family: 'Consolas', monospace; }
        .tabs { display: flex; gap: 0; background: #1a1a1a; border-bottom: 2px solid #333; margin-top: 0; position: sticky; top: 0; z-index: 10; }
        .tab { padding: 12px 20px; cursor: pointer; color: #888; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
        .tab:hover { color: #ccc; }
        .tab.active { color: #ef4444; border-bottom-color: #ef4444; }
        .panel { display: none; padding: 24px 0; }
        .panel.active { display: block; }
        .section { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
        .section-header { background: #222; padding: 10px 16px; font-weight: 600; font-size: 0.8rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em; }
        .section-body { padding: 0; }
        .code-snippet { overflow-x: auto; }
        .code-line { display: flex; font-family: 'Consolas', 'Monaco', monospace; font-size: 0.85rem; border-bottom: 1px solid #1e1e1e; }
        .code-line:last-child { border-bottom: none; }
        .code-line:hover { background: #222; }
        .code-line.highlight { background: #3f1515; }
        .line-num { width: 55px; padding: 3px 12px; text-align: right; color: #555; user-select: none; flex-shrink: 0; border-right: 1px solid #2a2a2a; }
        .line-code { padding: 3px 16px; white-space: pre; flex: 1; }
        .trace-item { padding: 8px 16px; border-bottom: 1px solid #222; font-family: 'Consolas', monospace; font-size: 0.83rem; display: flex; }
        .trace-item:last-child { border-bottom: none; }
        .trace-num { width: 30px; color: #555; flex-shrink: 0; }
        .trace-file { color: #60a5fa; }
        .trace-fn { color: #a78bfa; }
        .trace-line { color: #fbbf24; }
        .kv-table { width: 100%; }
        .kv-table tr { border-bottom: 1px solid #222; }
        .kv-table tr:last-child { border-bottom: none; }
        .kv-table td { padding: 6px 16px; font-family: 'Consolas', monospace; font-size: 0.83rem; vertical-align: top; }
        .kv-table td:first-child { width: 200px; color: #60a5fa; white-space: nowrap; }
        .kv-table td:last-child { color: #a0a0a0; word-break: break-all; }
        .empty-note { padding: 16px; color: #555; font-style: italic; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-red { background: #7f1d1d; color: #fca5a5; }
        .badge-blue { background: #1e3a5f; color: #93c5fd; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1><span class="badge badge-red">{$statusCode}</span> {$title}</h1>
            <div class="message">{$message}</div>
            <div class="location">{$file}:{$line}</div>
        </div>
    </div>
    <div class="tabs container">
        <div class="tab active" onclick="showPanel('stack')">Stack Trace</div>
        <div class="tab" onclick="showPanel('request')">Request</div>
        <div class="tab" onclick="showPanel('env')">Environment</div>
        {$previousHtml['tab']}
    </div>
    <div class="container">
        <div class="panel active" id="panel-stack">
            <div class="section">
                <div class="section-header">Source</div>
                <div class="section-body code-snippet">{$codeSnippet}</div>
            </div>
            <div class="section">
                <div class="section-header">Stack Trace</div>
                <div class="section-body">{$traceHtml}</div>
            </div>
        </div>
        <div class="panel" id="panel-request">{$requestHtml}</div>
        <div class="panel" id="panel-env">{$envHtml}</div>
        {$previousHtml['panel']}
    </div>
    <script>
    function showPanel(name) {
        document.querySelectorAll('.panel').forEach(function(p) { p.classList.remove('active'); });
        document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
        document.getElementById('panel-' + name).classList.add('active');
        event.target.classList.add('active');
    }
    </script>
</body>
</html>
HTML;
    }

    /**
     * Get syntax-highlighted code snippet around the error line.
     */
    protected static function getCodeSnippet(string $file, int $errorLine, int $padding = 10): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '<div class="empty-note">Unable to read source file</div>';
        }

        $lines = file($file);
        $start = max(0, $errorLine - $padding - 1);
        $end = min(count($lines), $errorLine + $padding);
        $html = '';

        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $highlight = $num === $errorLine ? ' highlight' : '';
            $content = htmlspecialchars(rtrim($lines[$i] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= "<div class=\"code-line{$highlight}\"><span class=\"line-num\">{$num}</span><span class=\"line-code\">{$content}</span></div>";
        }

        return $html;
    }

    /**
     * Format the stack trace.
     */
    protected static function formatTrace(\Throwable $e): string
    {
        $trace = $e->getTrace();
        $html = '';

        foreach ($trace as $i => $frame) {
            $file = htmlspecialchars($frame['file'] ?? '[internal]', ENT_QUOTES, 'UTF-8');
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $fn = htmlspecialchars($frame['function'] ?? '', ENT_QUOTES, 'UTF-8');

            $caller = $class ? htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . $type . $fn : $fn;

            $html .= "<div class=\"trace-item\">";
            $html .= "<span class=\"trace-num\">#{$i}</span>";
            $html .= "<span><span class=\"trace-file\">{$file}</span>:<span class=\"trace-line\">{$line}</span> <span class=\"trace-fn\">{$caller}()</span></span>";
            $html .= "</div>";
        }

        return $html ?: '<div class="empty-note">No stack trace available</div>';
    }

    /**
     * Format request information.
     */
    protected static function formatRequest(): string
    {
        $html = '';

        // Request info
        $method = htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'GET', ENT_QUOTES, 'UTF-8');
        $uri = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
        $protocol = htmlspecialchars($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1', ENT_QUOTES, 'UTF-8');
        $contentType = htmlspecialchars($_SERVER['CONTENT_TYPE'] ?? '-', ENT_QUOTES, 'UTF-8');

        $html .= '<div class="section"><div class="section-header">Request</div><div class="section-body"><table class="kv-table">';
        $html .= "<tr><td>Method</td><td>{$method}</td></tr>";
        $html .= "<tr><td>URI</td><td>{$uri}</td></tr>";
        $html .= "<tr><td>Protocol</td><td>{$protocol}</td></tr>";
        $html .= "<tr><td>Content-Type</td><td>{$contentType}</td></tr>";
        $html .= '</table></div></div>';

        // Headers
        $html .= '<div class="section"><div class="section-header">Headers</div><div class="section-body"><table class="kv-table">';
        $headerCount = 0;
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $html .= "<tr><td>{$name}</td><td>{$value}</td></tr>";
                $headerCount++;
            }
        }
        if ($headerCount === 0) {
            $html .= '<tr><td colspan="2" class="empty-note">No headers</td></tr>';
        }
        $html .= '</table></div></div>';

        // Query parameters
        $html .= '<div class="section"><div class="section-header">Query Parameters</div><div class="section-body"><table class="kv-table">';
        if (!empty($_GET)) {
            foreach ($_GET as $key => $value) {
                $key = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
                $value = htmlspecialchars(is_array($value) ? json_encode($value) : (string) $value, ENT_QUOTES, 'UTF-8');
                $html .= "<tr><td>{$key}</td><td>{$value}</td></tr>";
            }
        } else {
            $html .= '<tr><td colspan="2" class="empty-note">No query parameters</td></tr>';
        }
        $html .= '</table></div></div>';

        // POST data
        $html .= '<div class="section"><div class="section-header">POST Data</div><div class="section-body"><table class="kv-table">';
        if (!empty($_POST)) {
            foreach ($_POST as $key => $value) {
                $key = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
                // Mask sensitive fields
                $sensitive = ['password', 'secret', 'token', 'key', 'csrf', '_token'];
                if (in_array(strtolower($key), $sensitive, true)) {
                    $value = '********';
                } else {
                    $value = htmlspecialchars(is_array($value) ? json_encode($value) : (string) $value, ENT_QUOTES, 'UTF-8');
                }
                $html .= "<tr><td>{$key}</td><td>{$value}</td></tr>";
            }
        } else {
            $html .= '<tr><td colspan="2" class="empty-note">No POST data</td></tr>';
        }
        $html .= '</table></div></div>';

        // Cookies — mask session and auth cookie values to prevent exposure
        $html .= '<div class="section"><div class="section-header">Cookies</div><div class="section-body"><table class="kv-table">';
        if (!empty($_COOKIE)) {
            $sensitiveCookies = ['zephyr_session', 'PHPSESSID', 'auth_remember_token', 'maintenance_bypass', 'csrf_token', '_token'];
            foreach ($_COOKIE as $key => $value) {
                $safeKey = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
                if (in_array(strtolower($key), array_map('strtolower', $sensitiveCookies), true) || str_contains(strtolower($key), 'session') || str_contains(strtolower($key), 'token')) {
                    $safeValue = '********';
                } else {
                    $safeValue = htmlspecialchars(mb_substr((string) $value, 0, 100), ENT_QUOTES, 'UTF-8');
                    if (strlen((string) $value) > 100) {
                        $safeValue .= '...';
                    }
                }
                $html .= "<tr><td>{$safeKey}</td><td>{$safeValue}</td></tr>";
            }
        } else {
            $html .= '<tr><td colspan="2" class="empty-note">No cookies</td></tr>';
        }
        $html .= '</table></div></div>';

        return $html;
    }

    /**
     * Format previous exceptions in the chain.
     */
    protected static function formatPreviousExceptions(\Throwable $e): array
    {
        $previous = $e->getPrevious();
        if ($previous === null) {
            return ['tab' => '', 'panel' => ''];
        }

        $tab = '<div class="tab" onclick="showPanel(\'previous\')">Previous</div>';

        $panel = '<div class="panel" id="panel-previous">';
        $depth = 0;

        while ($previous !== null && $depth < 5) {
            $class = htmlspecialchars(get_class($previous), ENT_QUOTES, 'UTF-8');
            $msg = htmlspecialchars($previous->getMessage(), ENT_QUOTES, 'UTF-8');
            $file = htmlspecialchars($previous->getFile(), ENT_QUOTES, 'UTF-8');
            $line = $previous->getLine();

            $panel .= '<div class="section">';
            $panel .= "<div class=\"section-header\">Previous Exception #{$depth}: {$class}</div>";
            $panel .= '<div class="section-body"><table class="kv-table">';
            $panel .= "<tr><td>Message</td><td>{$msg}</td></tr>";
            $panel .= "<tr><td>File</td><td>{$file}:{$line}</td></tr>";
            $panel .= '</table></div></div>';

            $previous = $previous->getPrevious();
            $depth++;
        }

        $panel .= '</div>';

        return ['tab' => $tab, 'panel' => $panel];
    }

    /**
     * Format environment information.
     */
    protected static function formatEnvironment(): string
    {
        $html = '<div class="section"><div class="section-header">System</div><div class="section-body"><table class="kv-table">';

        $items = [
            'PHP Version' => PHP_VERSION,
            'PHP SAPI' => PHP_SAPI,
            'OS' => PHP_OS,
            'Environment' => $_ENV['ENV'] ?? 'dev',
            'Debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'Timezone' => date_default_timezone_get(),
            'Memory Usage' => self::formatBytes(memory_get_usage(true)),
            'Peak Memory' => self::formatBytes(memory_get_peak_usage(true)),
            'Max Memory' => ini_get('memory_limit'),
            'Max Execution' => ini_get('max_execution_time') . 's',
        ];

        foreach ($items as $key => $value) {
            $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $html .= "<tr><td>{$key}</td><td>{$value}</td></tr>";
        }

        $html .= '</table></div></div>';

        // Loaded extensions
        $html .= '<div class="section"><div class="section-header">Loaded Extensions</div><div class="section-body">';
        $html .= '<div style="padding:12px 16px; color:#888; font-family:Consolas,monospace; font-size:0.83rem; line-height:2;">';
        $extensions = get_loaded_extensions();
        sort($extensions);
        foreach ($extensions as $ext) {
            $html .= '<span class="badge badge-blue" style="margin:2px 4px;">' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . '</span> ';
        }
        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Format bytes to human readable.
     */
    protected static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
