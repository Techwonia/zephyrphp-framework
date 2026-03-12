<?php

declare(strict_types=1);

namespace ZephyrPHP\Marketplace;

/**
 * Marketplace Client — built into CMS admin to browse, search, and install
 * items from the ZephyrPHP marketplace.
 *
 * This client calls the Marketplace API (hosted on zephyrphp.com or self-hosted)
 * and handles one-click install of themes, apps, and sections.
 *
 * Features:
 * - Browse/search items by type and category
 * - One-click install (downloads ZIP → delegates to ThemeInstaller/AppInstaller)
 * - Auto-update checks
 * - License validation
 */
class MarketplaceClient
{
    private static ?MarketplaceClient $instance = null;

    private string $apiBaseUrl;
    private ?string $siteToken;
    private int $timeout = 15;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(
            $_ENV['MARKETPLACE_API_URL'] ?? 'https://marketplace.zephyrphp.com/api/v1',
            '/'
        );
        $this->siteToken = $_ENV['MARKETPLACE_SITE_TOKEN'] ?? null;
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    // ========================================================================
    // BROWSE & SEARCH
    // ========================================================================

    /**
     * Browse marketplace items with optional filters.
     *
     * @param array{type?: string, category?: string, search?: string, sort?: string, page?: int, per_page?: int} $filters
     * @return array{items: array, pagination: array, error?: string}
     */
    public function browse(array $filters = []): array
    {
        $query = http_build_query(array_filter([
            'type' => $filters['type'] ?? null,
            'category' => $filters['category'] ?? null,
            'search' => $filters['search'] ?? null,
            'sort' => $filters['sort'] ?? 'popular',
            'page' => $filters['page'] ?? 1,
            'per_page' => min(50, $filters['per_page'] ?? 20),
        ]));

        $response = $this->get('/items?' . $query);

        if (isset($response['error'])) {
            return ['items' => [], 'pagination' => [], 'error' => $response['error']];
        }

        return [
            'items' => $response['data'] ?? [],
            'pagination' => $response['pagination'] ?? [],
        ];
    }

    /**
     * Get a single marketplace item by slug.
     */
    public function getItem(string $slug): ?array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $response = $this->get('/items/' . $slug);

        if (isset($response['error'])) {
            return null;
        }

        return $response['data'] ?? null;
    }

    /**
     * Get reviews for an item.
     */
    public function getReviews(string $slug, int $page = 1): array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        return $this->get('/items/' . $slug . '/reviews?page=' . $page);
    }

    /**
     * Get available versions for an item.
     */
    public function getVersions(string $slug): array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $response = $this->get('/items/' . $slug . '/versions');
        return $response['data'] ?? [];
    }

    // ========================================================================
    // INSTALL & UPDATE
    // ========================================================================

    /**
     * Download and install an item from the marketplace.
     *
     * @return array{success: bool, slug?: string, error?: string}
     */
    public function install(string $slug, ?string $licenseKey = null): array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        // Get item info to determine type
        $item = $this->getItem($slug);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found on marketplace.'];
        }

        // Download ZIP
        $zipPath = $this->download($slug, $licenseKey);
        if (!$zipPath) {
            return ['success' => false, 'error' => 'Failed to download package from marketplace.'];
        }

        try {
            $type = $item['type'] ?? 'app';

            if ($type === 'theme') {
                $installer = new \ZephyrPHP\Cms\Services\ThemeInstaller(
                    new \ZephyrPHP\Cms\Services\ThemeManager()
                );
                return $installer->install($zipPath, false);
            } elseif ($type === 'app') {
                $installer = new \ZephyrPHP\App\AppInstaller(
                    \ZephyrPHP\App\AppManager::getInstance()
                );
                return $installer->install($zipPath, false);
            } elseif ($type === 'section') {
                return $this->installSection($zipPath);
            } else {
                return ['success' => false, 'error' => "Unknown item type: {$type}"];
            }
        } finally {
            // Clean up temp file
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    /**
     * Check for available updates for installed items.
     *
     * @param array<string, string> $installed slug => version map
     * @return array<string, array{current: string, latest: string, name: string}>
     */
    public function checkUpdates(array $installed): array
    {
        if (empty($installed)) {
            return [];
        }

        $response = $this->post('/updates/check', ['items' => $installed]);

        if (isset($response['error'])) {
            return [];
        }

        return $response['data'] ?? [];
    }

    // ========================================================================
    // REVIEWS
    // ========================================================================

    /**
     * Submit a review for a marketplace item.
     */
    public function submitReview(string $slug, int $rating, string $body): array
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'error' => 'Rating must be between 1 and 5.'];
        }

        return $this->post('/items/' . $slug . '/reviews', [
            'rating' => $rating,
            'body' => $body,
        ]);
    }

    // ========================================================================
    // DOWNLOAD HELPERS
    // ========================================================================

    /**
     * Download a ZIP package from the marketplace to a temp file.
     */
    private function download(string $slug, ?string $licenseKey = null): ?string
    {
        $url = $this->apiBaseUrl . '/items/' . $slug . '/download';

        $headers = ['Accept: application/octet-stream'];
        if ($this->siteToken) {
            $headers[] = 'Authorization: Bearer ' . $this->siteToken;
        }
        if ($licenseKey) {
            $headers[] = 'X-License-Key: ' . $licenseKey;
        }

        $tempFile = sys_get_temp_dir() . '/zephyr_marketplace_' . $slug . '_' . bin2hex(random_bytes(4)) . '.zip';

        $ch = curl_init($url);
        if (!$ch) return null;

        $fp = fopen($tempFile, 'w');
        if (!$fp) return null;

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200 || !file_exists($tempFile) || filesize($tempFile) === 0) {
            if (file_exists($tempFile)) unlink($tempFile);
            return null;
        }

        return $tempFile;
    }

    /**
     * Install a section package (single .twig file).
     */
    private function installSection(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open section ZIP.'];
        }

        try {
            // Find .twig file in ZIP
            $twigFile = null;
            $sectionJson = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = basename($zip->getNameIndex($i));
                if (str_ends_with($name, '.twig')) {
                    $twigFile = $zip->getNameIndex($i);
                } elseif ($name === 'section.json') {
                    $sectionJson = $zip->getFromIndex($i);
                }
            }

            if (!$twigFile) {
                return ['success' => false, 'error' => 'No .twig file found in section package.'];
            }

            // Get active theme path
            $themeManager = new \ZephyrPHP\Cms\Services\ThemeManager();
            $themePath = $themeManager->getActiveThemePath();
            $sectionsDir = $themePath . '/sections';

            if (!is_dir($sectionsDir)) {
                mkdir($sectionsDir, 0755, true);
            }

            $content = $zip->getFromName($twigFile);
            if ($content === false) {
                return ['success' => false, 'error' => 'Failed to read section template.'];
            }

            $destName = basename($twigFile);

            // Security: validate filename
            if (!preg_match('/^[a-z0-9_-]+\.twig$/', $destName)) {
                return ['success' => false, 'error' => 'Invalid section filename.'];
            }

            file_put_contents($sectionsDir . '/' . $destName, $content);

            return [
                'success' => true,
                'slug' => pathinfo($destName, PATHINFO_FILENAME),
                'name' => $sectionJson ? (json_decode($sectionJson, true)['name'] ?? $destName) : $destName,
            ];
        } finally {
            $zip->close();
        }
    }

    // ========================================================================
    // HTTP HELPERS
    // ========================================================================

    private function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    private function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->apiBaseUrl . $endpoint;

        $ch = curl_init($url);
        if (!$ch) {
            return ['error' => 'Failed to initialize HTTP client.'];
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ZephyrPHP-CMS/' . ($this->getVersion()),
        ];

        if ($this->siteToken) {
            $headers[] = 'Authorization: Bearer ' . $this->siteToken;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if ($method === 'POST' && $data !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            $headers[] = 'Content-Type: application/json';
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'Marketplace request failed: ' . ($error ?: 'unknown error')];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid response from marketplace (HTTP ' . $httpCode . ')'];
        }

        if ($httpCode >= 400) {
            return ['error' => $decoded['error']['message'] ?? $decoded['error'] ?? 'Marketplace error (HTTP ' . $httpCode . ')'];
        }

        return $decoded;
    }

    private function getVersion(): string
    {
        return '1.0.0';
    }
}
