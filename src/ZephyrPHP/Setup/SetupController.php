<?php

declare(strict_types=1);

namespace ZephyrPHP\Setup;

use ZephyrPHP\Core\Controllers\Controller;

class SetupController extends Controller
{
    /**
     * Show the setup wizard
     */
    public function index()
    {
        // If already installed, redirect to home
        if (file_exists(BASE_PATH . '/storage/.installed')) {
            $basePath = $_ENV['BASE_PATH'] ?? '';
            header('Location: ' . $basePath . '/');
            exit;
        }

        // Pre-flight writable check
        $writableErrors = [];
        $storagePath = BASE_PATH . '/storage';
        $envPath = BASE_PATH . '/.env';
        $envExamplePath = BASE_PATH . '/.env.example';

        if (!is_dir($storagePath)) {
            $writableErrors[] = "Directory does not exist: storage/";
        } elseif (!is_writable($storagePath)) {
            $writableErrors[] = "Directory is not writable: storage/";
        }

        // Check .env writability (or ability to create it)
        if (file_exists($envPath)) {
            if (!is_writable($envPath)) {
                $writableErrors[] = "File is not writable: .env";
            }
        } else {
            // .env doesn't exist yet; check if the directory is writable so we can create it
            if (!is_writable(BASE_PATH)) {
                $writableErrors[] = "Cannot create .env: project root is not writable";
            }
        }

        // Generate CSRF token for setup wizard
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['_setup_csrf'])) {
            $_SESSION['_setup_csrf'] = bin2hex(random_bytes(32));
        }

        // Read current .env values as defaults
        $env = $this->readEnv();

        return $this->render('@setup/wizard', [
            'env' => $env,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'csrf_token' => $_SESSION['_setup_csrf'],
            'writable_errors' => $writableErrors,
        ]);
    }

    /**
     * Save app settings (Step 1)
     */
    public function saveSettings()
    {
        header('Content-Type: application/json');

        if (!$this->validatePostRequest()) {
            exit;
        }

        try {
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $this->updateEnv([
                'APP_NAME' => $data['app_name'] ?? 'ZephyrPHP',
                'ENV' => $data['environment'] ?? 'dev',
                'APP_TIMEZONE' => $data['timezone'] ?? 'UTC',
            ]);

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $this->safeErrorMessage($e)]);
        }
        exit;
    }

    /**
     * Setup database — single step: save config, test, create DB if needed, install tables (Step 2)
     *
     * Flow:
     * 1. Validate & save DB credentials to .env
     * 2. Test server connection
     * 3. Check if database exists → create if missing (return prompt if no CREATE permission)
     * 4. Create only missing tables (CREATE TABLE IF NOT EXISTS) — never drops existing data
     * 5. Seed theme if no themes exist
     */
    public function setupDatabase()
    {
        header('Content-Type: application/json');

        if (!$this->validatePostRequest()) {
            exit;
        }

        try {
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $driver = $data['db_driver'] ?? 'pdo_mysql';

            // ── Step 1: Validate & save DB credentials to .env ──
            $envValues = ['DB_CONNECTION' => $driver];

            if ($driver === 'pdo_sqlite') {
                $dbPath = $data['db_path'] ?? 'database/database.sqlite';
                $dbPath = $this->validateSqlitePath($dbPath);
                if ($dbPath === null) {
                    exit;
                }
                $envValues['DB_DATABASE'] = $dbPath;
                $fullPath = BASE_PATH . '/' . $dbPath;
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                if (!file_exists($fullPath)) {
                    touch($fullPath);
                }
            } else {
                $dbHost = $data['db_host'] ?? '127.0.0.1';
                $dbPort = $data['db_port'] ?? '3306';
                $dbName = $data['db_name'] ?? 'zephyrphp';

                if (!preg_match('/^[a-zA-Z0-9.\-:]+$/', $dbHost)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid database host.']);
                    exit;
                }
                $dbPort = (string)(int)$dbPort;
                if ((int)$dbPort < 1 || (int)$dbPort > 65535) {
                    echo json_encode(['success' => false, 'error' => 'Invalid database port.']);
                    exit;
                }

                $envValues['DB_HOST'] = $dbHost;
                $envValues['DB_PORT'] = $dbPort;
                $envValues['DB_DATABASE'] = $dbName;
                $envValues['DB_USERNAME'] = $data['db_user'] ?? 'root';
                $envValues['DB_PASSWORD'] = $data['db_pass'] ?? '';
            }

            $this->updateEnv($envValues);

            // ── Step 2: Test server connection ──
            if ($driver === 'pdo_sqlite') {
                $fullPath = BASE_PATH . '/' . ($envValues['DB_DATABASE'] ?? 'database/database.sqlite');
                $pdo = new \PDO("sqlite:$fullPath");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            } else {
                $host = $envValues['DB_HOST'];
                $port = $envValues['DB_PORT'];
                $dbName = $envValues['DB_DATABASE'];
                $user = $envValues['DB_USERNAME'];
                $pass = $envValues['DB_PASSWORD'];

                $dsnMap = [
                    'pdo_mysql' => "mysql:host=$host;port=$port",
                    'pdo_pgsql' => "pgsql:host=$host;port=$port",
                    'pdo_sqlsrv' => "sqlsrv:Server=$host,$port",
                ];

                $dsn = $dsnMap[$driver] ?? "mysql:host=$host;port=$port";

                try {
                    $pdo = new \PDO($dsn, $user, $pass);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                } catch (\PDOException $e) {
                    echo json_encode(['success' => false, 'error' => $this->friendlyDbError($e->getMessage())]);
                    exit;
                }

                // ── Step 3: Check if database exists, create if needed ──
                $dbExists = false;
                if ($driver === 'pdo_mysql') {
                    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($dbName));
                    $dbExists = (bool) $stmt->fetch();
                } elseif ($driver === 'pdo_pgsql') {
                    $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datname = " . $pdo->quote($dbName));
                    $dbExists = (bool) $stmt->fetch();
                }

                if (!$dbExists) {
                    // Try to create the database
                    try {
                        $charset = $driver === 'pdo_mysql' ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . $this->quoteIdentifier($pdo, $dbName) . $charset);
                    } catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        if (str_contains($msg, 'Access denied') || str_contains($msg, 'permission denied') || str_contains($msg, '1044')) {
                            echo json_encode([
                                'success' => false,
                                'needs_create' => true,
                                'error' => "Database '$dbName' does not exist and cannot be created automatically. Please create it through your hosting control panel (cPanel, Plesk, etc.) and try again.",
                            ]);
                        } else {
                            echo json_encode(['success' => false, 'error' => $this->safeErrorMessage($e)]);
                        }
                        exit;
                    }
                }

                // Connect to the actual database now
                $dsnWithDb = [
                    'pdo_mysql' => "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
                    'pdo_pgsql' => "pgsql:host=$host;port=$port;dbname=$dbName",
                    'pdo_sqlsrv' => "sqlsrv:Server=$host,$port;Database=$dbName",
                ];
                $pdo = new \PDO($dsnWithDb[$driver] ?? $dsnWithDb['pdo_mysql'], $user, $pass);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }

            // ── Step 4: Check for existing tables — warn and drop on confirm ──
            $confirmed = !empty($data['confirm_existing']);

            $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $existingTables = [];
            if ($driverName === 'mysql') {
                $stmt = $pdo->query("SHOW TABLES");
                $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driverName === 'pgsql') {
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driverName === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }

            if (!empty($existingTables) && !$confirmed) {
                echo json_encode([
                    'success' => false,
                    'has_tables' => true,
                    'table_count' => count($existingTables),
                    'error' => 'This database has ' . count($existingTables) . ' existing table(s). All data will be lost and tables will be recreated fresh. Continue?',
                ]);
                exit;
            }

            // If confirmed, drop ALL existing tables first for a clean install
            if (!empty($existingTables) && $confirmed) {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($existingTables as $table) {
                    $quoted = $this->quoteIdentifier($pdo, $table);
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }

            // ── Step 5: Create fresh tables ──
            $results = [];

            $this->createTable($pdo, 'users', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(180) NOT NULL,
                `password` VARCHAR(255) NOT NULL,
                `rememberToken` VARCHAR(100) NULL DEFAULT NULL,
                `twoFactorSecret` VARCHAR(255) NULL DEFAULT NULL,
                `twoFactorEnabled` TINYINT(1) NOT NULL DEFAULT 0,
                `twoFactorRecoveryCodes` TEXT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_email` (`email`)
            ");
            $results[] = ['table' => 'users', 'status' => 'ready'];

            $this->createTable($pdo, 'roles', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_role_name` (`name`),
                UNIQUE KEY `uniq_role_slug` (`slug`)
            ");
            $results[] = ['table' => 'roles', 'status' => 'ready'];

            $this->createTable($pdo, 'role_user', "
                `user_id` INT UNSIGNED NOT NULL,
                `role_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`user_id`, `role_id`),
                CONSTRAINT `fk_ru_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ru_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
            ");
            $results[] = ['table' => 'role_user', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_themes', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                `description` TEXT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_theme_slug` (`slug`)
            ");
            $results[] = ['table' => 'cms_themes', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_collections', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `icon` VARCHAR(50) NULL DEFAULT NULL,
                `is_publishable` TINYINT(1) NOT NULL DEFAULT 0,
                `primary_key_type` VARCHAR(10) NOT NULL DEFAULT 'integer',
                `has_slug` TINYINT(1) NOT NULL DEFAULT 0,
                `slug_source_field` VARCHAR(100) NULL DEFAULT NULL,
                `display_field` VARCHAR(100) NULL DEFAULT NULL,
                `is_submittable` TINYINT(1) NOT NULL DEFAULT 0,
                `submit_settings` JSON NULL DEFAULT NULL,
                `url_prefix` VARCHAR(100) NULL DEFAULT NULL,
                `items_per_page` INT NOT NULL DEFAULT 20,
                `permissions` JSON NULL DEFAULT NULL,
                `seo_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `is_translatable` TINYINT(1) NOT NULL DEFAULT 0,
                `workflow_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `workflow_stages` JSON NULL DEFAULT NULL,
                `workflow_reviewers` JSON NULL DEFAULT NULL,
                `has_hierarchy` TINYINT(1) NOT NULL DEFAULT 0,
                `hierarchy_max_depth` INT NOT NULL DEFAULT 3,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_by` INT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_coll_slug` (`slug`)
            ");
            $results[] = ['table' => 'cms_collections', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_fields', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `collection_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `type` VARCHAR(50) NOT NULL DEFAULT 'text',
                `options` JSON NULL DEFAULT NULL,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `is_unique` TINYINT(1) NOT NULL DEFAULT 0,
                `is_searchable` TINYINT(1) NOT NULL DEFAULT 0,
                `is_sortable` TINYINT(1) NOT NULL DEFAULT 0,
                `is_filterable` TINYINT(1) NOT NULL DEFAULT 0,
                `is_listable` TINYINT(1) NOT NULL DEFAULT 1,
                `default_value` TEXT NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                CONSTRAINT `fk_cf_collection` FOREIGN KEY (`collection_id`) REFERENCES `cms_collections`(`id`) ON DELETE CASCADE
            ");
            $results[] = ['table' => 'cms_fields', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_media', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `path` VARCHAR(500) NOT NULL,
                `mime_type` VARCHAR(100) NOT NULL,
                `size` INT NOT NULL,
                `alt_text` VARCHAR(255) NULL DEFAULT NULL,
                `thumbnail_path` VARCHAR(500) NULL DEFAULT NULL,
                `uploaded_by` INT NULL DEFAULT NULL,
                `tags` TEXT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL
            ");
            $results[] = ['table' => 'cms_media', 'status' => 'ready'];

            // ── CMS Tables ──

            $this->createTable($pdo, 'cms_api_keys', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `key` VARCHAR(64) NOT NULL,
                `permissions` JSON NULL DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `expires_at` DATETIME NULL DEFAULT NULL,
                `last_used_at` DATETIME NULL DEFAULT NULL,
                `created_by` INT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_api_key` (`key`)
            ");
            $results[] = ['table' => 'cms_api_keys', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_revisions', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `entry_id` VARCHAR(50) NOT NULL,
                `data` JSON NOT NULL,
                `changed_fields` JSON NULL DEFAULT NULL,
                `action` VARCHAR(20) NOT NULL DEFAULT 'update',
                `user_id` INT NULL DEFAULT NULL,
                `user_name` VARCHAR(255) NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_rev_table_entry` (`table_name`, `entry_id`)
            ");
            $results[] = ['table' => 'cms_revisions', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_role_permissions', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `role_slug` VARCHAR(100) NOT NULL,
                `permissions` JSON NOT NULL,
                UNIQUE KEY `uniq_role_slug` (`role_slug`)
            ");
            $results[] = ['table' => 'cms_role_permissions', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_webhooks', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `topic` VARCHAR(100) NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `client_id` VARCHAR(64) NOT NULL,
                `secret` VARCHAR(128) NOT NULL,
                `format` VARCHAR(10) NOT NULL DEFAULT 'json',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `failure_count` INT NOT NULL DEFAULT 0,
                `last_error` TEXT NULL DEFAULT NULL,
                `last_success_at` DATETIME NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_webhook_topic` (`topic`, `is_active`),
                INDEX `idx_webhook_client` (`client_id`)
            ");
            $results[] = ['table' => 'cms_webhooks', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_activity_log', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `action` VARCHAR(50) NOT NULL,
                `resource_type` VARCHAR(50) NOT NULL,
                `resource_id` VARCHAR(100) NULL DEFAULT NULL,
                `resource_label` VARCHAR(255) NULL DEFAULT NULL,
                `user_id` INT NULL DEFAULT NULL,
                `user_name` VARCHAR(255) NULL DEFAULT NULL,
                `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                `meta` JSON NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_al_action` (`action`),
                INDEX `idx_al_resource` (`resource_type`, `resource_id`),
                INDEX `idx_al_user` (`user_id`),
                INDEX `idx_al_created` (`createdAt`)
            ");
            $results[] = ['table' => 'cms_activity_log', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_saved_views', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `collection_slug` VARCHAR(100) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(100) NOT NULL,
                `filters` JSON NOT NULL,
                `sort_by` VARCHAR(100) NULL DEFAULT NULL,
                `sort_dir` VARCHAR(4) NOT NULL DEFAULT 'DESC',
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_sv_collection` (`collection_slug`),
                UNIQUE KEY `uniq_sv_slug` (`collection_slug`, `slug`)
            ");
            $results[] = ['table' => 'cms_saved_views', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_content_templates', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `collection_slug` VARCHAR(255) NOT NULL,
                `data` JSON NULL DEFAULT NULL,
                `created_by` INT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_ct_collection` (`collection_slug`)
            ");
            $results[] = ['table' => 'cms_content_templates', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_notifications', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `body` TEXT NULL DEFAULT NULL,
                `link` VARCHAR(500) NULL DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `meta` JSON NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_notif_user_read` (`user_id`, `is_read`),
                INDEX `idx_notif_created` (`createdAt`)
            ");
            $results[] = ['table' => 'cms_notifications', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_notification_preferences', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `preferences` JSON NOT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_notif_pref_user` (`user_id`)
            ");
            $results[] = ['table' => 'cms_notification_preferences', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_languages', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `code` VARCHAR(10) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `native_name` VARCHAR(100) NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_lang_code` (`code`)
            ");
            $results[] = ['table' => 'cms_languages', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_translations', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `entry_id` VARCHAR(50) NOT NULL,
                `locale` VARCHAR(10) NOT NULL,
                `field_slug` VARCHAR(100) NOT NULL,
                `value` TEXT NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_translation` (`table_name`, `entry_id`, `locale`, `field_slug`),
                INDEX `idx_trans_entry` (`table_name`, `entry_id`),
                INDEX `idx_trans_locale` (`locale`)
            ");
            $results[] = ['table' => 'cms_translations', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_redirects', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `from_path` VARCHAR(2048) NOT NULL,
                `to_url` VARCHAR(2048) NOT NULL,
                `status_code` INT NOT NULL DEFAULT 301,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `hit_count` INT NOT NULL DEFAULT 0,
                `last_hit_at` DATETIME NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL
            ");
            $results[] = ['table' => 'cms_redirects', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_sessions', "
                `id` VARCHAR(128) NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NULL DEFAULT NULL,
                `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                `user_agent` TEXT NULL DEFAULT NULL,
                `payload` TEXT NOT NULL,
                `last_activity` INT NOT NULL,
                INDEX `idx_sess_user` (`user_id`),
                INDEX `idx_sess_activity` (`last_activity`)
            ");
            $results[] = ['table' => 'cms_sessions', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_plugins', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(100) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `version` VARCHAR(20) NOT NULL DEFAULT '1.0.0',
                `is_active` TINYINT(1) NOT NULL DEFAULT 0,
                `settings` JSON NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_plugin_slug` (`slug`)
            ");
            $results[] = ['table' => 'cms_plugins', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_dashboard_layouts', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `layout` JSON NOT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uniq_user_layout` (`user_id`)
            ");
            $results[] = ['table' => 'cms_dashboard_layouts', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_workflow_transitions', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `entry_id` VARCHAR(50) NOT NULL,
                `from_stage` VARCHAR(50) NOT NULL,
                `to_stage` VARCHAR(50) NOT NULL,
                `user_id` INT NULL DEFAULT NULL,
                `user_name` VARCHAR(255) NULL DEFAULT NULL,
                `comment` TEXT NULL DEFAULT NULL,
                `action` VARCHAR(20) NOT NULL DEFAULT 'advance',
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_wft_entry` (`table_name`, `entry_id`),
                INDEX `idx_wft_created` (`createdAt`)
            ");
            $results[] = ['table' => 'cms_workflow_transitions', 'status' => 'ready'];

            $this->createTable($pdo, 'cms_invitations', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL,
                `token` VARCHAR(255) NOT NULL,
                `role_id` INT NULL DEFAULT NULL,
                `invited_by` INT NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `accepted_at` DATETIME NULL DEFAULT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                `updatedAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_inv_email` (`email`)
            ");
            $results[] = ['table' => 'cms_invitations', 'status' => 'ready'];

            $this->createTable($pdo, 'password_resets', "
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL,
                `token` VARCHAR(255) NOT NULL,
                `createdAt` DATETIME NULL DEFAULT NULL,
                INDEX `idx_pr_email` (`email`)
            ");
            $results[] = ['table' => 'password_resets', 'status' => 'ready'];

            // Write CMS tables-installed flag
            $flagDir = BASE_PATH . '/storage/cms';
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0755, true);
            }
            @file_put_contents($flagDir . '/.tables-installed', '2', LOCK_EX);

            // ── Seed theme if none exist ──
            // Walk themes/{slug}/ and accept either the Phase-1 manifest
            // (theme.blueprint.json) or the legacy theme.json. First match
            // wins and is marked live.
            $stmt = $pdo->query("SELECT COUNT(*) FROM `cms_themes`");
            if ((int) $stmt->fetchColumn() === 0) {
                $themesBase = BASE_PATH . '/themes';
                if (is_dir($themesBase)) {
                    $seeded = false;
                    foreach (scandir($themesBase) as $entry) {
                        if ($seeded) break;
                        if ($entry === '.' || $entry === '..') continue;
                        $dir = $themesBase . '/' . $entry;
                        if (!is_dir($dir)) continue;

                        $manifest = null;
                        foreach (['theme.blueprint.json', 'theme.json'] as $candidate) {
                            if (file_exists($dir . '/' . $candidate)) {
                                $manifest = $dir . '/' . $candidate;
                                break;
                            }
                        }
                        if (!$manifest) continue;

                        $config = json_decode((string) file_get_contents($manifest), true);
                        $name = $config['name'] ?? ucfirst($entry);
                        $safeSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($entry));
                        if ($safeSlug === '') continue;

                        $insert = $pdo->prepare("INSERT INTO `cms_themes` (`name`, `slug`, `status`, `createdAt`, `updatedAt`) VALUES (?, ?, 'live', NOW(), NOW())");
                        $insert->execute([$name, $safeSlug]);
                        $seeded = true;
                    }
                }
            }

            echo json_encode(['success' => true, 'tables' => $results]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $this->friendlyDbError($e->getMessage())]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $this->safeErrorMessage($e)]);
        }
        exit;
    }

    /**
     * Create admin account (Step 4)
     */
    public function createAdmin()
    {
        header('Content-Type: application/json');

        if (!$this->validatePostRequest()) {
            exit;
        }

        try {
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';
            $passwordConfirm = $data['password_confirmation'] ?? '';

            // Validate
            $errors = [];
            if (empty($name)) $errors[] = 'Name is required';
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
            if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters';
            if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match';

            if (!empty($errors)) {
                echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
                exit;
            }

            $env = $this->readEnv();
            $pdo = $this->connectWithEnv($env);

            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'A user with this email already exists']);
                exit;
            }

            // Create user
            $now = date('Y-m-d H:i:s');
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hashedPassword, $now, $now]);
            $userId = $pdo->lastInsertId();

            // Create admin role if not exists
            $stmt = $pdo->prepare("SELECT id FROM `roles` WHERE `slug` = 'admin'");
            $stmt->execute();
            $role = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$role) {
                $stmt = $pdo->prepare("INSERT INTO `roles` (`name`, `slug`, `description`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute(['Admin', 'admin', 'Full administrator access', $now, $now]);
                $roleId = $pdo->lastInsertId();
            } else {
                $roleId = $role['id'];
            }

            // Assign admin role
            $stmt = $pdo->prepare("INSERT INTO `role_user` (`user_id`, `role_id`) VALUES (?, ?)");
            $stmt->execute([(int) $userId, (int) $roleId]);

            // Auto-complete: generate APP_KEY, create .installed, create uploads dir
            $env = $this->readEnv();
            if (empty($env['APP_KEY'])) {
                $key = 'base64:' . base64_encode(random_bytes(32));
                $this->updateEnv(['APP_KEY' => $key]);
            }

            $installedFile = BASE_PATH . '/storage/.installed';
            $storageDir = dirname($installedFile);
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $written = @file_put_contents($installedFile, json_encode([
                'installed_at' => date('Y-m-d H:i:s'),
                'version' => '1.0.0',
            ]), LOCK_EX);
            if ($written === false) {
                throw new \RuntimeException('Failed to create .installed file. Check storage/ directory permissions.');
            }

            $uploadsDir = BASE_PATH . '/public/uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            // Auto-login the admin user
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = (int) $userId;

            // ── Security: permanently delete setup files ──
            // Prevents re-running setup if .installed is ever deleted
            $this->destroySetupFiles();

            echo json_encode([
                'success' => true,
                'message' => 'Setup complete!',
                'redirect' => '/' . ltrim($_ENV['ADMIN_PATH'] ?? 'admin', '/'),
            ]);
        } catch (\Throwable $e) {
            http_response_code(200);
            echo json_encode(['success' => false, 'error' => $this->safeErrorMessage($e)]);
        }
        exit;
    }

    // ─── Setup Self-Destruct ────────────────────────────────

    /**
     * Permanently delete all setup wizard files after successful installation.
     * This prevents the setup wizard from ever being accessible again,
     * even if the .installed file is deleted by an attacker.
     */
    private function destroySetupFiles(): void
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();

        $filesToDelete = [
            $basePath . '/app/Setup/SetupController.php',
            $basePath . '/pages/setup/wizard.twig',
        ];

        $dirsToDelete = [
            $basePath . '/pages/setup',
            $basePath . '/app/Setup',
        ];

        // Delete files first
        foreach ($filesToDelete as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Delete empty directories
        foreach ($dirsToDelete as $dir) {
            if (is_dir($dir)) {
                // Only delete if directory is empty (or only has dotfiles)
                $remaining = @scandir($dir);
                $remaining = array_diff($remaining ?: [], ['.', '..']);
                if (empty($remaining)) {
                    @rmdir($dir);
                }
            }
        }
    }

    // ─── Security Helpers ────────────────────────────────────

    /**
     * Validate CSRF token and installation status for POST endpoints.
     * Returns true if validation passes, false if it failed (and response was sent).
     */
    private function validatePostRequest(): bool
    {
        // Defense-in-depth: block POST requests after installation
        if (file_exists(BASE_PATH . '/storage/.installed')) {
            echo json_encode(['success' => false, 'error' => 'Application already installed.']);
            return false;
        }

        // CSRF validation
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['_setup_csrf'] ?? '', $token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
            return false;
        }

        return true;
    }

    /**
     * Validate SQLite database path to prevent path traversal.
     * Returns the validated path or null if invalid (and response was sent).
     */
    private function validateSqlitePath(string $dbPath): ?string
    {
        if (str_contains($dbPath, '..') || str_contains($dbPath, "\0") || preg_match('#^[/\\\\]#', $dbPath)) {
            echo json_encode(['success' => false, 'error' => 'Invalid database path.']);
            return null;
        }

        // Verify the resolved path is within BASE_PATH
        $fullPath = BASE_PATH . '/' . $dbPath;
        $parentDir = dirname($fullPath);
        if (is_dir($parentDir)) {
            $realParent = realpath($parentDir);
            $realBase = realpath(BASE_PATH);
            if ($realParent === false || $realBase === false || !str_starts_with($realParent, $realBase)) {
                echo json_encode(['success' => false, 'error' => 'Invalid database path.']);
                return null;
            }
        }

        return $dbPath;
    }

    /**
     * Format error message based on debug mode.
     */
    private function safeErrorMessage(\Throwable $e): string
    {
        error_log('Setup error: ' . $e->getMessage());
        return ($_ENV['APP_DEBUG'] ?? false) ? $e->getMessage() : 'An error occurred. Check server logs.';
    }

    // ─── Helpers ──────────────────────────────────────────

    private function readEnv(): array
    {
        $envPath = BASE_PATH . '/.env';
        if (!file_exists($envPath)) {
            // Copy from .env.example
            $examplePath = BASE_PATH . '/.env.example';
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
            } else {
                file_put_contents($envPath, '');
            }
        }

        $values = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value);
            }
        }
        return $values;
    }

    private function updateEnv(array $updates): void
    {
        $envPath = BASE_PATH . '/.env';
        $content = file_exists($envPath) ? file_get_contents($envPath) : '';

        foreach ($updates as $key => $value) {
            // Strip newlines and null bytes to prevent .env injection
            $value = str_replace(["\r", "\n", "\0"], '', $value);
            $escaped = (str_contains($value, ' ') || str_contains($value, '#') || str_contains($value, '"') || str_contains($value, "'"))
                ? '"' . addslashes($value) . '"'
                : $value;
            if (preg_match("/^" . preg_quote($key, '/') . "=.*/m", $content)) {
                $content = preg_replace("/^" . preg_quote($key, '/') . "=.*/m", "$key=$escaped", $content);
            } else {
                $content = rtrim($content) . "\n$key=$escaped";
            }
        }

        file_put_contents($envPath, $content);
    }

    private function connectWithEnv(array $env): \PDO
    {
        $driver = $env['DB_CONNECTION'] ?? 'pdo_mysql';

        if ($driver === 'pdo_sqlite') {
            $dbPath = $env['DB_DATABASE'] ?? 'database/database.sqlite';
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = BASE_PATH . '/' . $dbPath;
            }
            $pdo = new \PDO("sqlite:$dbPath");
        } else {
            $host = $env['DB_HOST'] ?? '127.0.0.1';
            $port = $env['DB_PORT'] ?? '3306';
            $dbName = $env['DB_DATABASE'] ?? 'zephyrphp';
            $user = $env['DB_USERNAME'] ?? 'root';
            $pass = $env['DB_PASSWORD'] ?? '';

            $dsnMap = [
                'pdo_mysql' => "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
                'pdo_pgsql' => "pgsql:host=$host;port=$port;dbname=$dbName",
                'pdo_sqlsrv' => "sqlsrv:Server=$host,$port;Database=$dbName",
            ];

            $dsn = $dsnMap[$driver] ?? "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass);
        }

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function createTable(\PDO $pdo, string $name, string $columns): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $engine = ($driver === 'mysql') ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $quoted = $this->quoteIdentifier($pdo, $name);
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$quoted} ($columns){$engine}");
    }

    private function quoteIdentifier(\PDO $pdo, string $identifier): string
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function friendlyDbError(string $message): string
    {
        if (str_contains($message, 'Connection refused')) {
            return 'Connection refused. Is your database server running?';
        }
        if (str_contains($message, 'Access denied')) {
            return 'Access denied. Check your username and password.';
        }
        if (str_contains($message, 'Unknown database') || str_contains($message, 'does not exist')) {
            return 'Database not found. Click "Create Database" to create it.';
        }
        if (str_contains($message, 'could not find driver')) {
            return 'PHP database driver not installed. Install the required PHP extension.';
        }
        // Don't leak raw PDO error messages
        error_log('Setup DB error: ' . $message);
        return 'Database connection failed. Check your credentials and try again.';
    }
}
