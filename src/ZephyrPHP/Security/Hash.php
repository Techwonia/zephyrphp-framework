<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * Password Hashing Service
 *
 * Provides secure password hashing using PHP's built-in password_hash()
 * with support for Argon2id (preferred), Argon2i, and bcrypt algorithms.
 *
 * Usage:
 *   // Hash a password
 *   $hash = Hash::make('secret123');
 *
 *   // Verify a password
 *   if (Hash::check('secret123', $hash)) { ... }
 *
 *   // Check if rehashing is needed
 *   if (Hash::needsRehash($hash)) { ... }
 */
class Hash
{
    /** @var string Default algorithm */
    private static string $algorithm = PASSWORD_ARGON2ID;

    /** @var array Algorithm options */
    private static array $options = [];

    /** @var array Bcrypt options */
    private static array $bcryptOptions = [
        'cost' => 12,
    ];

    /** @var array Argon2 options */
    private static array $argon2Options = [
        'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
        'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
    ];

    /**
     * Hash a password
     *
     * @param string $password The plain text password
     * @param array $options Optional algorithm-specific options
     * @return string The hashed password
     * @throws \RuntimeException If hashing fails
     */
    public static function make(string $password, array $options = []): string
    {
        $algo = self::$algorithm;
        $opts = array_merge(self::getDefaultOptions(), self::$options, $options);

        $hash = password_hash($password, $algo, $opts);

        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed');
        }

        return $hash;
    }

    /**
     * Verify a password against a hash
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $password The plain text password to verify
     * @param string $hash The hash to verify against
     * @return bool True if the password matches
     */
    public static function check(string $password, string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed
     *
     * Use this when users log in to upgrade their password hash
     * if the algorithm or options have changed.
     *
     * @param string $hash The hash to check
     * @param array $options Optional algorithm-specific options
     * @return bool True if rehashing is needed
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        $opts = array_merge(self::getDefaultOptions(), self::$options, $options);

        return password_needs_rehash($hash, self::$algorithm, $opts);
    }

    /**
     * Get password hash info
     *
     * @param string $hash The hash to analyze
     * @return array Hash information (algo, algoName, options)
     */
    public static function info(string $hash): array
    {
        return password_get_info($hash);
    }

    /**
     * Set the hashing algorithm
     *
     * @param string $algorithm PASSWORD_ARGON2ID, PASSWORD_ARGON2I, or PASSWORD_BCRYPT
     */
    public static function setAlgorithm(string $algorithm): void
    {
        $validAlgorithms = [PASSWORD_BCRYPT, PASSWORD_ARGON2I, PASSWORD_ARGON2ID];

        if (!in_array($algorithm, $validAlgorithms, true)) {
            throw new \InvalidArgumentException('Invalid hashing algorithm');
        }

        self::$algorithm = $algorithm;
    }

    /**
     * Use Argon2id algorithm (recommended)
     */
    public static function useArgon2id(): void
    {
        self::$algorithm = PASSWORD_ARGON2ID;
    }

    /**
     * Use Argon2i algorithm
     */
    public static function useArgon2i(): void
    {
        self::$algorithm = PASSWORD_ARGON2I;
    }

    /**
     * Use bcrypt algorithm
     */
    public static function useBcrypt(): void
    {
        self::$algorithm = PASSWORD_BCRYPT;
    }

    /**
     * Set custom options for the algorithm
     */
    public static function setOptions(array $options): void
    {
        self::$options = $options;
    }

    /**
     * Set bcrypt cost factor
     *
     * @param int $cost Cost factor (4-31, recommended: 10-12)
     */
    public static function setBcryptCost(int $cost): void
    {
        if ($cost < 4 || $cost > 31) {
            throw new \InvalidArgumentException('Bcrypt cost must be between 4 and 31');
        }

        self::$bcryptOptions['cost'] = $cost;
    }

    /**
     * Set Argon2 parameters
     *
     * @param int $memoryCost Memory cost in kibibytes
     * @param int $timeCost Number of iterations
     * @param int $threads Number of parallel threads
     */
    public static function setArgon2Options(int $memoryCost, int $timeCost, int $threads): void
    {
        self::$argon2Options = [
            'memory_cost' => $memoryCost,
            'time_cost' => $timeCost,
            'threads' => $threads,
        ];
    }

    /**
     * Get default options for current algorithm
     */
    private static function getDefaultOptions(): array
    {
        if (self::$algorithm === PASSWORD_BCRYPT) {
            return self::$bcryptOptions;
        }

        return self::$argon2Options;
    }

    /**
     * Generate a secure random token
     *
     * @param int $length Token length in bytes (will be hex encoded to 2x length)
     * @return string Hexadecimal token
     */
    public static function randomToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a URL-safe random token
     *
     * @param int $length Token length in bytes
     * @return string Base64url-encoded token
     */
    public static function randomUrlSafeToken(int $length = 32): string
    {
        $bytes = random_bytes($length);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Timing-safe string comparison
     *
     * @param string $known The known string
     * @param string $user The user-provided string
     * @return bool True if strings are equal
     */
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    /**
     * Reset to default configuration
     */
    public static function reset(): void
    {
        self::$algorithm = PASSWORD_ARGON2ID;
        self::$options = [];
        self::$bcryptOptions = ['cost' => 12];
        self::$argon2Options = [
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ];
    }
}
