<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * Rate Limiter
 *
 * Protects against brute force attacks and API abuse by limiting
 * the number of requests per time window.
 *
 * Supports multiple storage drivers:
 * - File (default, no dependencies)
 * - Redis (recommended for production)
 * - APCu (in-memory, single server)
 * - Database
 *
 * Usage:
 *   // Basic rate limiting
 *   if (RateLimiter::tooManyAttempts('login:'.$ip, 5)) {
 *       return response('Too many attempts', 429);
 *   }
 *   RateLimiter::hit('login:'.$ip, 60); // 60 second decay
 *
 *   // Using the attempt helper
 *   $executed = RateLimiter::attempt('api:'.$userId, 100, function() {
 *       // Handle request
 *   }, 60);
 */
class RateLimiter
{
    private const RATE_LIMITS_DIR = '/storage/rate_limits';

    /** @var string Storage driver */
    private static string $driver = 'file';

    /** @var string File storage path */
    private static string $storagePath = '';

    /** @var \Redis|null Redis connection */
    private static ?\Redis $redis = null;

    /** @var \PDO|null Database connection */
    private static ?\PDO $pdo = null;

    /** @var array In-memory cache for current request */
    private static array $cache = [];

    /** @var array Configuration */
    private static array $config = [
        'prefix' => 'rate_limit:',
        'default_max_attempts' => 60,
        'default_decay_seconds' => 60,
    ];

    /**
     * Determine if too many attempts have been made
     *
     * @param string $key Unique identifier (e.g., 'login:192.168.1.1')
     * @param int $maxAttempts Maximum allowed attempts
     * @return bool True if rate limited
     */
    public static function tooManyAttempts(string $key, int $maxAttempts = 60): bool
    {
        return self::attempts($key) >= $maxAttempts;
    }

    /**
     * Increment the counter for a given key
     *
     * @param string $key Unique identifier
     * @param int $decaySeconds Time until the counter resets
     * @return int The new number of attempts
     */
    public static function hit(string $key, int $decaySeconds = 60): int
    {
        $key = self::$config['prefix'] . $key;

        return match (self::$driver) {
            'redis' => self::hitRedis($key, $decaySeconds),
            'apcu' => self::hitApcu($key, $decaySeconds),
            'database' => self::hitDatabase($key, $decaySeconds),
            default => self::hitFile($key, $decaySeconds),
        };
    }

    /**
     * Get the number of attempts for a given key
     *
     * @param string $key Unique identifier
     * @return int Number of attempts
     */
    public static function attempts(string $key): int
    {
        $key = self::$config['prefix'] . $key;

        return match (self::$driver) {
            'redis' => self::attemptsRedis($key),
            'apcu' => self::attemptsApcu($key),
            'database' => self::attemptsDatabase($key),
            default => self::attemptsFile($key),
        };
    }

    /**
     * Get the number of remaining attempts
     *
     * @param string $key Unique identifier
     * @param int $maxAttempts Maximum allowed attempts
     * @return int Remaining attempts
     */
    public static function remaining(string $key, int $maxAttempts = 60): int
    {
        return max(0, $maxAttempts - self::attempts($key));
    }

    /**
     * Get the number of seconds until the rate limit resets
     *
     * @param string $key Unique identifier
     * @return int Seconds until reset (0 if not limited)
     */
    public static function availableIn(string $key): int
    {
        $key = self::$config['prefix'] . $key;

        return match (self::$driver) {
            'redis' => self::ttlRedis($key),
            'apcu' => self::ttlApcu($key),
            'database' => self::ttlDatabase($key),
            default => self::ttlFile($key),
        };
    }

    /**
     * Clear the rate limit for a given key
     *
     * @param string $key Unique identifier
     */
    public static function clear(string $key): void
    {
        $key = self::$config['prefix'] . $key;

        match (self::$driver) {
            'redis' => self::clearRedis($key),
            'apcu' => self::clearApcu($key),
            'database' => self::clearDatabase($key),
            default => self::clearFile($key),
        };

        unset(self::$cache[$key]);
    }

    /**
     * Attempt to execute a callback if not rate limited
     *
     * @param string $key Unique identifier
     * @param int $maxAttempts Maximum allowed attempts
     * @param callable $callback The callback to execute
     * @param int $decaySeconds Time until the counter resets
     * @return mixed The callback result or false if rate limited
     */
    public static function attempt(
        string $key,
        int $maxAttempts,
        callable $callback,
        int $decaySeconds = 60
    ): mixed {
        if (self::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        self::hit($key, $decaySeconds);

        return $callback();
    }

    /**
     * Get rate limit headers for response
     *
     * @param string $key Unique identifier
     * @param int $maxAttempts Maximum allowed attempts
     * @return array Headers array
     */
    public static function headers(string $key, int $maxAttempts = 60): array
    {
        $remaining = self::remaining($key, $maxAttempts);
        $retryAfter = self::availableIn($key);

        return [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => time() + $retryAfter,
            'Retry-After' => $retryAfter,
        ];
    }

    // =========================================================================
    // DRIVER CONFIGURATION
    // =========================================================================

    /**
     * Set the storage driver
     *
     * @param string $driver 'file', 'redis', 'apcu', or 'database'
     */
    public static function driver(string $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Configure the rate limiter
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        if (isset($config['storage_path'])) {
            self::$storagePath = $config['storage_path'];
        }
    }

    /**
     * Set Redis connection
     */
    public static function setRedis(\Redis $redis): void
    {
        self::$redis = $redis;
        self::$driver = 'redis';
    }

    /**
     * Set database connection
     */
    public static function setDatabase(\PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$driver = 'database';
    }

    // =========================================================================
    // FILE DRIVER
    // =========================================================================

    private static function hitFile(string $key, int $decaySeconds): int
    {
        $file = self::getFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Use exclusive lock around the full read-increment-write cycle
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return 0;
        }

        flock($fh, LOCK_EX);

        $content = stream_get_contents($fh);
        $data = $content ? (json_decode($content, true) ?? ['attempts' => 0, 'expires' => 0]) : ['attempts' => 0, 'expires' => 0];
        $now = time();

        // Reset if expired
        if ($data['expires'] < $now) {
            $data = ['attempts' => 0, 'expires' => $now + $decaySeconds];
        }

        $data['attempts']++;

        // Rewrite file contents
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);

        flock($fh, LOCK_UN);
        fclose($fh);

        self::$cache[$key] = $data;

        return $data['attempts'];
    }

    private static function attemptsFile(string $key): int
    {
        $data = self::getFileData($key);

        if ($data['expires'] < time()) {
            return 0;
        }

        return $data['attempts'];
    }

    private static function ttlFile(string $key): int
    {
        $data = self::getFileData($key);
        $ttl = $data['expires'] - time();

        return max(0, $ttl);
    }

    private static function clearFile(string $key): void
    {
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private static function getFileData(string $key): array
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $file = self::getFilePath($key);

        if (!file_exists($file)) {
            return ['attempts' => 0, 'expires' => 0];
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true) ?? ['attempts' => 0, 'expires' => 0];

        self::$cache[$key] = $data;

        return $data;
    }

    private static function saveFileData(string $key, array $data): void
    {
        $file = self::getFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
        self::$cache[$key] = $data;
    }

    private static function getFilePath(string $key): string
    {
        $path = self::$storagePath ?: (defined('BASE_PATH') ? BASE_PATH . self::RATE_LIMITS_DIR : sys_get_temp_dir() . '/rate_limits');
        $hash = hash('sha256', $key);

        return $path . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    // =========================================================================
    // REDIS DRIVER
    // =========================================================================

    private static function hitRedis(string $key, int $decaySeconds): int
    {
        $redis = self::getRedis();

        $attempts = $redis->incr($key);

        if ($attempts === 1) {
            $redis->expire($key, $decaySeconds);
        }

        return (int) $attempts;
    }

    private static function attemptsRedis(string $key): int
    {
        return (int) (self::getRedis()->get($key) ?? 0);
    }

    private static function ttlRedis(string $key): int
    {
        $ttl = self::getRedis()->ttl($key);
        return max(0, (int) $ttl);
    }

    private static function clearRedis(string $key): void
    {
        self::getRedis()->del($key);
    }

    private static function getRedis(): \Redis
    {
        if (self::$redis === null) {
            self::$redis = new \Redis();
            self::$redis->connect(
                $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                (int) ($_ENV['REDIS_PORT'] ?? 6379)
            );

            if (!empty($_ENV['REDIS_PASSWORD'])) {
                self::$redis->auth($_ENV['REDIS_PASSWORD']);
            }
        }

        return self::$redis;
    }

    // =========================================================================
    // APCU DRIVER
    // =========================================================================

    private static function hitApcu(string $key, int $decaySeconds): int
    {
        $attempts = apcu_inc($key, 1, $success);

        if (!$success) {
            apcu_store($key, 1, $decaySeconds);
            return 1;
        }

        return (int) $attempts;
    }

    private static function attemptsApcu(string $key): int
    {
        return (int) (apcu_fetch($key) ?? 0);
    }

    private static function ttlApcu(string $key): int
    {
        $info = apcu_key_info($key);
        if ($info === false) {
            return 0;
        }

        $ttl = ($info['creation_time'] + $info['ttl']) - time();
        return max(0, $ttl);
    }

    private static function clearApcu(string $key): void
    {
        apcu_delete($key);
    }

    // =========================================================================
    // DATABASE DRIVER
    // =========================================================================

    private static function hitDatabase(string $key, int $decaySeconds): int
    {
        $pdo = self::getDatabase();
        $now = time();
        $expires = $now + $decaySeconds;

        // Try to update existing record
        $stmt = $pdo->prepare(
            'UPDATE rate_limits SET attempts = attempts + 1 WHERE `key` = ? AND expires_at > ?'
        );
        $stmt->execute([$key, $now]);

        if ($stmt->rowCount() === 0) {
            // Insert new record
            $stmt = $pdo->prepare(
                'INSERT INTO rate_limits (`key`, attempts, expires_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE attempts = 1, expires_at = ?'
            );
            $stmt->execute([$key, $expires, $expires]);
            return 1;
        }

        // Get current attempts
        $stmt = $pdo->prepare('SELECT attempts FROM rate_limits WHERE `key` = ?');
        $stmt->execute([$key]);

        return (int) ($stmt->fetchColumn() ?? 0);
    }

    private static function attemptsDatabase(string $key): int
    {
        $pdo = self::getDatabase();

        $stmt = $pdo->prepare(
            'SELECT attempts FROM rate_limits WHERE `key` = ? AND expires_at > ?'
        );
        $stmt->execute([$key, time()]);

        return (int) ($stmt->fetchColumn() ?? 0);
    }

    private static function ttlDatabase(string $key): int
    {
        $pdo = self::getDatabase();

        $stmt = $pdo->prepare('SELECT expires_at FROM rate_limits WHERE `key` = ?');
        $stmt->execute([$key]);

        $expires = $stmt->fetchColumn();
        if ($expires === false) {
            return 0;
        }

        return max(0, (int) $expires - time());
    }

    private static function clearDatabase(string $key): void
    {
        $pdo = self::getDatabase();

        $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE `key` = ?');
        $stmt->execute([$key]);
    }

    private static function getDatabase(): \PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database connection not configured for rate limiter');
        }

        return self::$pdo;
    }

    /**
     * Create database table for rate limiting
     *
     * Run this during setup:
     * CREATE TABLE rate_limits (
     *     `key` VARCHAR(255) PRIMARY KEY,
     *     attempts INT UNSIGNED NOT NULL DEFAULT 0,
     *     expires_at INT UNSIGNED NOT NULL,
     *     INDEX idx_expires (expires_at)
     * ) ENGINE=InnoDB;
     */
    public static function createTable(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS rate_limits (
                `key` VARCHAR(255) PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at INT UNSIGNED NOT NULL,
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB
        ');
    }

    /**
     * Clean up expired entries (for file and database drivers)
     */
    public static function cleanup(): void
    {
        match (self::$driver) {
            'database' => self::cleanupDatabase(),
            'file' => self::cleanupFiles(),
            default => null,
        };
    }

    private static function cleanupDatabase(): void
    {
        $pdo = self::getDatabase();
        $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE expires_at < ?');
        $stmt->execute([time()]);
    }

    private static function cleanupFiles(): void
    {
        $path = self::$storagePath ?: (defined('BASE_PATH') ? BASE_PATH . self::RATE_LIMITS_DIR : sys_get_temp_dir() . '/rate_limits');

        if (!is_dir($path)) {
            return;
        }

        $now = time();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);

            if ($data && isset($data['expires']) && $data['expires'] < $now) {
                unlink($file->getPathname());
            }
        }
    }

    /**
     * Reset state (for testing)
     */
    public static function reset(): void
    {
        self::$driver = 'file';
        self::$storagePath = '';
        self::$redis = null;
        self::$pdo = null;
        self::$cache = [];
    }
}
