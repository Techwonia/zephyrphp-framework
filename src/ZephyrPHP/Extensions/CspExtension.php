<?php

declare(strict_types=1);

namespace ZephyrPHP\Extensions;

use ZephyrPHP\Security\Nonce;
use ZephyrPHP\Security\Headers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension for Content Security Policy
 *
 * Provides Twig functions for working with CSP nonces and security features.
 * Enables strict CSP by allowing inline scripts and styles with nonces.
 *
 * Usage in templates:
 *   <script nonce="{{ csp_nonce() }}">...</script>
 *   <style nonce="{{ csp_style_nonce() }}">...</style>
 *   {{ csp_script('console.log("hello")') }}
 *   {{ csp_style('.foo { color: red; }') }}
 */
class CspExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Nonce functions
            new TwigFunction('csp_nonce', [$this, 'getNonce']),
            new TwigFunction('csp_style_nonce', [$this, 'getStyleNonce']),
            new TwigFunction('nonce', [$this, 'getNonce']), // Alias
            new TwigFunction('style_nonce', [$this, 'getStyleNonce']), // Alias

            // Nonce attribute helpers
            new TwigFunction('nonce_attr', [$this, 'getNonceAttribute'], ['is_safe' => ['html']]),
            new TwigFunction('style_nonce_attr', [$this, 'getStyleNonceAttribute'], ['is_safe' => ['html']]),

            // Inline script/style with nonce
            new TwigFunction('csp_script', [$this, 'inlineScript'], ['is_safe' => ['html']]),
            new TwigFunction('csp_style', [$this, 'inlineStyle'], ['is_safe' => ['html']]),

            // Meta tag for AJAX requests
            new TwigFunction('csp_meta', [$this, 'metaTag'], ['is_safe' => ['html']]),

            // CSP configuration helpers
            new TwigFunction('csp_report_uri', [$this, 'setReportUri']),
            new TwigFunction('csp_level', [$this, 'setCspLevel']),
        ];
    }

    /**
     * Get the current request's script nonce
     */
    public function getNonce(): string
    {
        return Nonce::generate();
    }

    /**
     * Get the current request's style nonce
     */
    public function getStyleNonce(): string
    {
        return Nonce::style();
    }

    /**
     * Get nonce as HTML attribute
     *
     * @return string e.g., 'nonce="abc123..."'
     */
    public function getNonceAttribute(): string
    {
        return Nonce::attribute();
    }

    /**
     * Get style nonce as HTML attribute
     */
    public function getStyleNonceAttribute(): string
    {
        return Nonce::styleAttribute();
    }

    /**
     * Generate an inline script tag with nonce
     *
     * @param string $code JavaScript code
     * @param array $attributes Additional script attributes
     */
    public function inlineScript(string $code, array $attributes = []): string
    {
        $attrs = $this->buildAttributes(array_merge(
            ['nonce' => Nonce::generate()],
            $attributes
        ));

        return "<script{$attrs}>\n{$code}\n</script>";
    }

    /**
     * Generate an inline style tag with nonce
     *
     * @param string $css CSS code
     * @param array $attributes Additional style attributes
     */
    public function inlineStyle(string $css, array $attributes = []): string
    {
        $attrs = $this->buildAttributes(array_merge(
            ['nonce' => Nonce::style()],
            $attributes
        ));

        return "<style{$attrs}>\n{$css}\n</style>";
    }

    /**
     * Generate a meta tag with the CSP nonce for AJAX requests
     *
     * JavaScript can read this meta tag to include the nonce in dynamic scripts.
     */
    public function metaTag(): string
    {
        $scriptNonce = htmlspecialchars(Nonce::generate(), ENT_QUOTES, 'UTF-8');
        $styleNonce = htmlspecialchars(Nonce::style(), ENT_QUOTES, 'UTF-8');

        return '<meta name="csp-nonce" content="' . $scriptNonce . '">' . "\n"
            . '<meta name="csp-style-nonce" content="' . $styleNonce . '">';
    }

    /**
     * Set CSP report URI (call before headers are sent)
     */
    public function setReportUri(string $uri): void
    {
        Headers::setReportUri($uri);
    }

    /**
     * Set CSP enforcement level
     *
     * @param string $level 'strict', 'moderate', or 'relaxed'
     */
    public function setCspLevel(string $level): void
    {
        Headers::setCspLevel($level);
    }

    /**
     * Build HTML attributes string
     */
    private function buildAttributes(array $attributes): string
    {
        if (empty($attributes)) {
            return '';
        }

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

        return $parts ? ' ' . implode(' ', $parts) : '';
    }
}
