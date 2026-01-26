<?php

declare(strict_types=1);

namespace ZephyrPHP\Extensions;

use ZephyrPHP\Asset\Asset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension for Asset Management
 *
 * Provides Twig functions for working with CSS, JavaScript, images, and other assets.
 * Integrates with the Asset manager for versioning, CDN, and build tool support.
 */
class AssetExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Basic asset URL generation
            new TwigFunction('asset', [$this, 'asset']),
            new TwigFunction('asset_url', [$this, 'asset']), // Alias

            // CSS functions
            new TwigFunction('css', [$this, 'css'], ['is_safe' => ['html']]),
            new TwigFunction('stylesheet', [$this, 'css'], ['is_safe' => ['html']]), // Alias
            new TwigFunction('enqueue_css', [$this, 'enqueueCss']),
            new TwigFunction('render_css', [$this, 'renderCss'], ['is_safe' => ['html']]),
            new TwigFunction('inline_css', [$this, 'inlineCss']),

            // JavaScript functions
            new TwigFunction('js', [$this, 'js'], ['is_safe' => ['html']]),
            new TwigFunction('script', [$this, 'js'], ['is_safe' => ['html']]), // Alias
            new TwigFunction('enqueue_js', [$this, 'enqueueJs']),
            new TwigFunction('render_js', [$this, 'renderJs'], ['is_safe' => ['html']]),
            new TwigFunction('render_js_head', [$this, 'renderJsHead'], ['is_safe' => ['html']]),
            new TwigFunction('inline_js', [$this, 'inlineJs']),

            // Image functions
            new TwigFunction('image', [$this, 'image'], ['is_safe' => ['html']]),
            new TwigFunction('img', [$this, 'image'], ['is_safe' => ['html']]), // Alias

            // Preloading and performance hints
            new TwigFunction('preload', [$this, 'preload'], ['is_safe' => ['html']]),
            new TwigFunction('preload_font', [$this, 'preloadFont'], ['is_safe' => ['html']]),
            new TwigFunction('prefetch', [$this, 'prefetch'], ['is_safe' => ['html']]),
            new TwigFunction('preconnect', [$this, 'preconnect'], ['is_safe' => ['html']]),
            new TwigFunction('render_preloads', [$this, 'renderPreloads'], ['is_safe' => ['html']]),
            new TwigFunction('enqueue_preload', [$this, 'enqueuePreload']),

            // Collection functions
            new TwigFunction('load_assets', [$this, 'loadAssets']),
            new TwigFunction('load_collection', [$this, 'loadAssets']), // Alias
            new TwigFunction('has_collection', [$this, 'hasCollection']),

            // Data URI for small inline assets
            new TwigFunction('data_uri', [$this, 'dataUri']),

            // External CDN assets
            new TwigFunction('external_css', [$this, 'externalCss'], ['is_safe' => ['html']]),
            new TwigFunction('external_js', [$this, 'externalJs'], ['is_safe' => ['html']]),
            new TwigFunction('enqueue_external_css', [$this, 'enqueueExternalCss']),
            new TwigFunction('enqueue_external_js', [$this, 'enqueueExternalJs']),
            new TwigFunction('cdn', [$this, 'externalCss'], ['is_safe' => ['html']]), // Alias

            // Utility functions
            new TwigFunction('is_production', [$this, 'isProduction']),
            new TwigFunction('cdn_url', [$this, 'getCdnUrl']),
        ];
    }

    /**
     * Get asset URL with versioning
     */
    public function asset(string $path, array $options = []): string
    {
        return Asset::url($path, $options);
    }

    /**
     * Generate CSS link tag
     */
    public function css(string $path, array $attributes = []): string
    {
        return Asset::css($path, $attributes);
    }

    /**
     * Queue CSS for later output
     */
    public function enqueueCss(string $path, array $attributes = [], int $priority = 10): void
    {
        Asset::enqueueCss($path, $attributes, $priority);
    }

    /**
     * Render all queued CSS
     */
    public function renderCss(): string
    {
        return Asset::renderCss();
    }

    /**
     * Add inline CSS
     */
    public function inlineCss(string $css): void
    {
        Asset::inlineCss($css);
    }

    /**
     * Generate JavaScript script tag
     */
    public function js(string $path, array $attributes = []): string
    {
        return Asset::js($path, $attributes);
    }

    /**
     * Queue JavaScript for later output
     */
    public function enqueueJs(string $path, array $attributes = [], bool $inHead = false, int $priority = 10): void
    {
        Asset::enqueueJs($path, $attributes, $inHead, $priority);
    }

    /**
     * Render all queued JavaScript for body
     */
    public function renderJs(): string
    {
        return Asset::renderJs();
    }

    /**
     * Render all queued JavaScript for head
     */
    public function renderJsHead(): string
    {
        return Asset::renderJsHead();
    }

    /**
     * Add inline JavaScript
     */
    public function inlineJs(string $js, bool $inHead = false): void
    {
        Asset::inlineJs($js, $inHead);
    }

    /**
     * Generate image tag with lazy loading
     */
    public function image(string $path, ?string $alt = null, array $attributes = []): string
    {
        return Asset::image($path, $alt, $attributes);
    }

    /**
     * Generate preload link tag
     */
    public function preload(string $path, string $as, array $attributes = []): string
    {
        return Asset::preload($path, $as, $attributes);
    }

    /**
     * Generate preload link tag for font
     */
    public function preloadFont(string $path, array $attributes = []): string
    {
        return Asset::preload($path, 'font', $attributes);
    }

    /**
     * Generate prefetch link tag
     */
    public function prefetch(string $path, string $as = 'script'): string
    {
        return Asset::prefetch($path, $as);
    }

    /**
     * Generate preconnect link tag
     */
    public function preconnect(string $url, bool $crossorigin = false): string
    {
        return Asset::preconnect($url, $crossorigin);
    }

    /**
     * Queue a preload resource
     */
    public function enqueuePreload(string $path, string $as, array $attributes = []): void
    {
        Asset::enqueuePreload($path, $as, $attributes);
    }

    /**
     * Render all queued preloads
     */
    public function renderPreloads(): string
    {
        return Asset::renderPreloads();
    }

    /**
     * Load a registered asset collection
     */
    public function loadAssets(string $name): void
    {
        Asset::load($name);
    }

    /**
     * Check if a collection exists
     */
    public function hasCollection(string $name): bool
    {
        return Asset::hasCollection($name);
    }

    /**
     * Get data URI for small assets
     */
    public function dataUri(string $path): ?string
    {
        return Asset::dataUri($path);
    }

    /**
     * Generate CSS link tag for external CDN asset
     */
    public function externalCss(string $nameOrUrl, array $attributes = []): string
    {
        return Asset::externalCss($nameOrUrl, $attributes);
    }

    /**
     * Generate JavaScript script tag for external CDN asset
     */
    public function externalJs(string $nameOrUrl, array $attributes = []): string
    {
        return Asset::externalJs($nameOrUrl, $attributes);
    }

    /**
     * Queue external CSS for later output
     */
    public function enqueueExternalCss(string $nameOrUrl, array $attributes = [], int $priority = 10): void
    {
        Asset::enqueueExternalCss($nameOrUrl, $attributes, $priority);
    }

    /**
     * Queue external JavaScript for later output
     */
    public function enqueueExternalJs(string $nameOrUrl, array $attributes = [], bool $inHead = false, int $priority = 10): void
    {
        Asset::enqueueExternalJs($nameOrUrl, $attributes, $inHead, $priority);
    }

    /**
     * Check if running in production mode
     */
    public function isProduction(): bool
    {
        return Asset::isProduction();
    }

    /**
     * Get CDN URL if configured
     */
    public function getCdnUrl(): ?string
    {
        return Asset::getCdnUrl();
    }
}
