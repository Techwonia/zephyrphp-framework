<?php

declare(strict_types=1);

namespace ZephyrPHP\App;

use ZephyrPHP\Event\EventDispatcher;
use ZephyrPHP\Event\Events\AppInstalled;
use ZephyrPHP\Event\Events\AppUninstalled;
use ZephyrPHP\Hook\HookManager;

/**
 * App Installer — handles ZIP package installation, validation, updates, and removal.
 *
 * Expected app package format (ZIP):
 *   app.json            (required — metadata + main class reference)
 *   src/                (required — PHP source files)
 *     MyApp.php         (main class extending MarketplaceApp)
 *   views/              (optional — Twig templates)
 *   assets/             (optional — css, js, images)
 *   migrations/         (optional — numbered PHP migration files)
 *   routes/             (optional — route definition files)
 *   config.json         (optional — default settings)
 *   preview.png         (optional — marketplace screenshot)
 *
 * Security:
 * - ZIP contents are validated before extraction (no path traversal)
 * - Only allowed file extensions are extracted
 * - app.json is validated against required schema
 * - File sizes are checked before extraction
 * - PHP files only allowed in src/, migrations/, routes/ directories
 * - Asset publishing uses realpath verification
 */
class AppInstaller
{
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;      // 10MB per file
    private const int MAX_TOTAL_SIZE = 50 * 1024 * 1024;     // 50MB total

    private const array ALLOWED_EXTENSIONS = [
        'twig', 'json', 'css', 'js', 'map',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
        'txt', 'md',
    ];

    /**
     * Directories where PHP files are permitted.
     */
    private const array PHP_ALLOWED_DIRS = ['src/', 'migrations/', 'routes/'];

    /**
     * Required fields in app.json.
     */
    private const array REQUIRED_FIELDS = ['name', 'main'];

    private AppManager $appManager;

    public function __construct(AppManager $appManager)
    {
        $this->appManager = $appManager;
    }

    /**
     * Install an app from a ZIP file.
     *
     * @param string $zipPath Path to the uploaded ZIP file
     * @param bool $overwrite Whether to overwrite an existing app with the same slug
     * @return array{success: bool, slug?: string, name?: string, error?: string}
     */
    public function install(string $zipPath, bool $overwrite = false): array
    {
        if (!file_exists($zipPath) || !is_readable($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found or not readable.'];
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file. Error code: ' . $result];
        }

        try {
            // Find and validate app.json
            $appJson = $this->findAppJson($zip);
            if ($appJson === null) {
                return ['success' => false, 'error' => 'app.json not found in ZIP archive.'];
            }

            $config = json_decode($appJson, true);
            if (!is_array($config)) {
                return ['success' => false, 'error' => 'app.json contains invalid JSON.'];
            }

            $validationError = $this->validateAppJson($config);
            if ($validationError !== null) {
                return ['success' => false, 'error' => $validationError];
            }

            // Determine slug
            $slug = $config['slug'] ?? $this->slugify($config['name']);
            if (empty($slug) || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
                return ['success' => false, 'error' => 'Invalid app slug derived from name.'];
            }

            // Check if already installed
            $appPath = $this->appManager->getAppsPath() . '/' . $slug;
            if (is_dir($appPath) && !$overwrite) {
                return ['success' => false, 'error' => "App '{$slug}' already exists. Set overwrite to replace."];
            }

            // Validate all ZIP entries
            $contentError = $this->validateZipContents($zip);
            if ($contentError !== null) {
                return ['success' => false, 'error' => $contentError];
            }

            // Extract
            $extractError = $this->extractApp($zip, $appPath);
            if ($extractError !== null) {
                return ['success' => false, 'error' => $extractError];
            }

            // Write validated app.json with slug
            $config['slug'] = $slug;
            file_put_contents(
                $appPath . '/app.json',
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // Register in app manager
            $this->appManager->addToRegistry($slug, false);

            // Call install() lifecycle hook
            $app = $this->instantiateApp($slug, $appPath, $config);
            if ($app) {
                try {
                    $app->install();
                } catch (\Throwable $e) {
                    error_log("App '{$slug}' install() failed: " . $e->getMessage());
                }
            }

            // Publish assets
            $this->publishAssets($slug);

            // Fire events
            EventDispatcher::getInstance()->dispatch(new AppInstalled($slug, $config['name'], $appPath));
            HookManager::getInstance()->doAction('app.installed', $slug, $config);

            return [
                'success' => true,
                'slug' => $slug,
                'name' => $config['name'],
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Uninstall an app — removes files, published assets, registry entry.
     * Cannot uninstall enabled apps (must disable first).
     *
     * @return array{success: bool, error?: string}
     */
    public function uninstall(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);

        if ($this->appManager->isEnabled($slug)) {
            return ['success' => false, 'error' => 'Cannot uninstall an enabled app. Disable it first.'];
        }

        $appPath = $this->appManager->getAppsPath() . '/' . $slug;

        // Call uninstall() lifecycle hook
        if (is_dir($appPath)) {
            $configFile = $appPath . '/app.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
                $app = $this->instantiateApp($slug, $appPath, $config);
                if ($app) {
                    try {
                        $app->uninstall();
                    } catch (\Throwable $e) {
                        error_log("App '{$slug}' uninstall() failed: " . $e->getMessage());
                    }
                }
            }
        }

        // Remove published assets
        $this->unpublishAssets($slug);

        // Remove app files
        if (is_dir($appPath)) {
            $this->deleteDirectory($appPath);
        }

        // Remove from registry
        $this->appManager->removeFromRegistry($slug);

        // Fire events
        EventDispatcher::getInstance()->dispatch(new AppUninstalled($slug));
        HookManager::getInstance()->doAction('app.uninstalled', $slug);

        return ['success' => true];
    }

    /**
     * Update an app from a new ZIP — preserves config.json (user settings).
     *
     * @return array{success: bool, slug?: string, error?: string}
     */
    public function update(string $slug, string $zipPath): array
    {
        $slug = $this->sanitizeSlug($slug);
        $appPath = $this->appManager->getAppsPath() . '/' . $slug;

        if (!is_dir($appPath)) {
            return ['success' => false, 'error' => "App '{$slug}' is not installed."];
        }

        // Backup user config
        $configBackup = null;
        $userConfig = $appPath . '/config.json';
        if (file_exists($userConfig)) {
            $configBackup = file_get_contents($userConfig);
        }

        $wasEnabled = $this->appManager->isEnabled($slug);

        // Disable before updating
        if ($wasEnabled) {
            $this->appManager->disable($slug);
        }

        // Reinstall (overwrite)
        $result = $this->install($zipPath, true);

        if ($result['success']) {
            // Restore user config
            if ($configBackup !== null) {
                file_put_contents($appPath . '/config.json', $configBackup);
            }

            // Re-enable if it was enabled
            if ($wasEnabled) {
                $this->appManager->enable($slug);
            }
        }

        return $result;
    }

    // ========================================================================
    // ASSET PUBLISHING
    // ========================================================================

    /**
     * Publish app assets to public/assets/apps/{slug}/.
     */
    public function publishAssets(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        $appPath = $this->appManager->getAppsPath() . '/' . $slug;
        $assetsSource = $appPath . '/assets';

        if (!is_dir($assetsSource)) {
            return true;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publishDir = $basePath . '/public/assets/apps/' . $slug;

        if (is_dir($publishDir)) {
            $this->deleteDirectory($publishDir);
        }

        $this->copyDirectory($assetsSource, $publishDir);

        // Verify published dir is within public/assets
        $realPublic = realpath($basePath . '/public/assets');
        $realPublish = realpath($publishDir);
        if (!$realPublic || !$realPublish || !str_starts_with($realPublish, $realPublic)) {
            if (is_dir($publishDir)) {
                $this->deleteDirectory($publishDir);
            }
            return false;
        }

        return true;
    }

    /**
     * Remove published assets for an app.
     */
    public function unpublishAssets(string $slug): void
    {
        $slug = $this->sanitizeSlug($slug);
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $publishDir = $basePath . '/public/assets/apps/' . $slug;

        if (is_dir($publishDir)) {
            $realPublic = realpath($basePath . '/public/assets');
            $realPublish = realpath($publishDir);
            if ($realPublic && $realPublish && str_starts_with($realPublish, $realPublic)) {
                $this->deleteDirectory($publishDir);
            }
        }
    }

    // ========================================================================
    // VALIDATION
    // ========================================================================

    /**
     * Validate app.json contents.
     *
     * @return string|null Error message or null if valid
     */
    public function validateAppJson(array $config): ?string
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($config[$field])) {
                return "app.json is missing required field: '{$field}'.";
            }
        }

        if (!is_string($config['name']) || strlen($config['name']) > 100) {
            return 'app.json name must be a string of max 100 characters.';
        }

        if (!is_string($config['main']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $config['main'])) {
            return "app.json main class must be a valid class name (e.g., 'MySeoApp').";
        }

        if (isset($config['version'])) {
            if (!is_string($config['version']) || !preg_match('/^\d+\.\d+(\.\d+)?(-[\w.]+)?$/', $config['version'])) {
                return "app.json version must be a valid semver string (e.g., '1.0.0').";
            }
        }

        if (isset($config['slug'])) {
            if (!is_string($config['slug']) || !preg_match('/^[a-z0-9_-]+$/', $config['slug'])) {
                return 'app.json slug must contain only lowercase letters, numbers, underscores, and hyphens.';
            }
            if (strlen($config['slug']) > 64) {
                return 'app.json slug must be 64 characters or fewer.';
            }
        }

        if (isset($config['namespace'])) {
            if (!is_string($config['namespace']) || !preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]*$/', $config['namespace'])) {
                return 'app.json namespace must be a valid PHP namespace.';
            }
        }

        return null;
    }

    /**
     * Find app.json in the ZIP (root or one level deep).
     */
    private function findAppJson(\ZipArchive $zip): ?string
    {
        $content = $zip->getFromName('app.json');
        if ($content !== false) {
            return $content;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^[^/]+/app\.json$#', $name)) {
                return $zip->getFromName($name);
            }
        }

        return null;
    }

    /**
     * Validate all entries in the ZIP.
     *
     * @return string|null Error message or null if valid
     */
    private function validateZipContents(\ZipArchive $zip): ?string
    {
        $totalSize = 0;
        $prefix = $this->detectZipPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $relativePath = $prefix ? substr($name, strlen($prefix)) : $name;

            if (str_ends_with($name, '/')) {
                continue;
            }

            // Block path traversal
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
                return "Dangerous path detected: '{$relativePath}'.";
            }

            // Check file size
            if ($stat['size'] > self::MAX_FILE_SIZE) {
                return "File '{$relativePath}' exceeds maximum size of 10MB.";
            }

            $totalSize += $stat['size'];
            if ($totalSize > self::MAX_TOTAL_SIZE) {
                return 'Total extracted size exceeds maximum of 50MB.';
            }

            // Check file extension
            $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

            if ($ext === 'php') {
                // PHP files only in allowed directories
                $inAllowedDir = false;
                foreach (self::PHP_ALLOWED_DIRS as $dir) {
                    if (str_starts_with($relativePath, $dir)) {
                        $inAllowedDir = true;
                        break;
                    }
                }
                if (!$inAllowedDir) {
                    return "PHP files are only allowed in src/, migrations/, and routes/ directories: '{$relativePath}'.";
                }
            } elseif (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                return "File type '.{$ext}' not allowed: '{$relativePath}'.";
            }
        }

        return null;
    }

    // ========================================================================
    // EXTRACTION
    // ========================================================================

    private function detectZipPrefix(\ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            return '';
        }

        $firstName = $zip->getNameIndex(0);
        if (str_ends_with($firstName, '/')) {
            $prefix = $firstName;
            for ($i = 1; $i < $zip->numFiles; $i++) {
                if (!str_starts_with($zip->getNameIndex($i), $prefix)) {
                    return '';
                }
            }
            return $prefix;
        }

        return '';
    }

    private function extractApp(\ZipArchive $zip, string $targetPath): ?string
    {
        if (is_dir($targetPath)) {
            $this->deleteDirectory($targetPath);
        }

        if (!mkdir($targetPath, 0755, true)) {
            return 'Failed to create app directory.';
        }

        $prefix = $this->detectZipPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $relativePath = $prefix ? substr($name, strlen($prefix)) : $name;

            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }

            if (str_starts_with(basename($relativePath), '.')) {
                continue;
            }

            $destFile = $targetPath . '/' . $relativePath;
            $destDir = dirname($destFile);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $realTarget = realpath($targetPath);
            $realDest = realpath($destDir);
            if (!$realTarget || !$realDest || !str_starts_with($realDest, $realTarget)) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($destFile, $content);
            }
        }

        return null;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Instantiate an app from its config (for lifecycle hooks).
     */
    private function instantiateApp(string $slug, string $appPath, array $config): ?MarketplaceApp
    {
        $mainClass = $config['main'] ?? null;
        if (!$mainClass) {
            return null;
        }

        $mainFile = $appPath . '/src/' . $mainClass . '.php';
        if (!file_exists($mainFile)) {
            return null;
        }

        require_once $mainFile;

        $namespace = $config['namespace'] ?? '';
        $fqcn = $namespace ? $namespace . '\\' . $mainClass : $mainClass;

        if (!class_exists($fqcn)) {
            return null;
        }

        $instance = new $fqcn();
        if (!$instance instanceof MarketplaceApp) {
            return null;
        }

        $instance->setContext($slug, $appPath, $config);
        return $instance;
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($slug)));
        if (empty($slug)) {
            throw new \InvalidArgumentException('Invalid app slug.');
        }
        return $slug;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'app';
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
