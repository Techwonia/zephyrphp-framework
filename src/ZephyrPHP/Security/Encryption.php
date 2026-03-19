<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * Encryption Service
 *
 * Provides symmetric encryption using AES-256-GCM (authenticated encryption).
 * Protects sensitive data at rest and in transit.
 *
 * Usage:
 *   // Set your encryption key (should be in .env)
 *   Encryption::setKey(env('APP_KEY'));
 *
 *   // Encrypt data
 *   $encrypted = Encryption::encrypt('sensitive data');
 *
 *   // Decrypt data
 *   $decrypted = Encryption::decrypt($encrypted);
 *
 *   // Encrypt arrays/objects
 *   $encrypted = Encryption::encryptArray(['user_id' => 123]);
 */
class Encryption
{
    /** @var string The encryption key */
    private static string $key = '';

    /** @var string The cipher algorithm */
    private static string $cipher = 'aes-256-gcm';

    /** @var int Tag length for GCM mode */
    private const TAG_LENGTH = 16;

    /**
     * Encrypt a string value
     *
     * @param string $value The value to encrypt
     * @return string Base64-encoded encrypted data
     * @throws \RuntimeException If encryption fails
     */
    public static function encrypt(string $value): string
    {
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::$cipher));
        $tag = '';

        $encrypted = openssl_encrypt(
            $value,
            self::$cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            error_log('Encryption failed: ' . openssl_error_string());
            throw new \RuntimeException('Encryption failed. Check server logs for details.');
        }

        // Combine IV + Tag + Encrypted data
        $payload = json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'value' => base64_encode($encrypted),
            'mac' => self::hash($iv . $encrypted),
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload);
    }

    /**
     * Decrypt an encrypted string
     *
     * @param string $payload The encrypted payload
     * @return string The decrypted value
     * @throws \RuntimeException If decryption fails
     */
    public static function decrypt(string $payload): string
    {
        $payload = self::getJsonPayload($payload);

        $iv = base64_decode($payload['iv']);
        $tag = base64_decode($payload['tag']);
        $encrypted = base64_decode($payload['value']);

        // Verify MAC
        if (!self::validMac($payload, $iv . $encrypted)) {
            throw new \RuntimeException('MAC verification failed');
        }

        $decrypted = openssl_decrypt(
            $encrypted,
            self::$cipher,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            error_log('Decryption failed: ' . openssl_error_string());
            throw new \RuntimeException('Decryption failed. Check server logs for details.');
        }

        return $decrypted;
    }

    /**
     * Encrypt an array or object
     *
     * @param mixed $value The value to encrypt
     * @return string Base64-encoded encrypted data
     */
    public static function encryptArray(mixed $value): string
    {
        return self::encrypt(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * Decrypt to an array
     *
     * @param string $payload The encrypted payload
     * @return array The decrypted array
     */
    public static function decryptArray(string $payload): array
    {
        $decrypted = self::decrypt($payload);
        return json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encrypt a string (alias for consistency)
     */
    public static function encryptString(string $value): string
    {
        return self::encrypt($value);
    }

    /**
     * Decrypt a string (alias for consistency)
     */
    public static function decryptString(string $payload): string
    {
        return self::decrypt($payload);
    }

    /**
     * Generate a new encryption key
     *
     * @return string Base64-encoded 256-bit key
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Set the encryption key
     *
     * @param string $key The encryption key (plain or base64: prefixed)
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    /**
     * Set the cipher algorithm
     *
     * @param string $cipher The OpenSSL cipher (e.g., 'aes-256-gcm', 'aes-256-cbc')
     */
    public static function setCipher(string $cipher): void
    {
        if (!in_array($cipher, openssl_get_cipher_methods())) {
            throw new \InvalidArgumentException("Unsupported cipher: {$cipher}");
        }

        self::$cipher = $cipher;
    }

    /**
     * Create a keyed hash (HMAC)
     *
     * @param string $value The value to hash
     * @return string The hash
     */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::getKey());
    }

    /**
     * Verify a keyed hash
     *
     * @param string $value The original value
     * @param string $hash The hash to verify
     * @return bool True if valid
     */
    public static function verifyHash(string $value, string $hash): bool
    {
        return hash_equals(self::hash($value), $hash);
    }

    /**
     * Create a signed URL token
     *
     * @param array $data Data to include in the token
     * @param int $expiration Expiration timestamp
     * @return string The signed token
     */
    public static function signedToken(array $data, int $expiration): string
    {
        $data['_expires'] = $expiration;
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $signature = self::hash($payload);

        return base64_encode($payload) . '.' . $signature;
    }

    /**
     * Verify and decode a signed token
     *
     * @param string $token The signed token
     * @return array|null The data or null if invalid/expired
     */
    public static function verifySignedToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $payload = base64_decode($encodedPayload);

        // Verify signature
        if (!hash_equals(self::hash($payload), $signature)) {
            return null;
        }

        $data = json_decode($payload, true);
        if ($data === null) {
            return null;
        }

        // Check expiration
        if (isset($data['_expires']) && $data['_expires'] < time()) {
            return null;
        }

        unset($data['_expires']);
        return $data;
    }

    /**
     * Get the encryption key
     *
     * @return string The binary key
     * @throws \RuntimeException If no key is set
     */
    private static function getKey(): string
    {
        $key = self::$key ?: ($_ENV['APP_KEY'] ?? '');

        if (empty($key)) {
            throw new \RuntimeException(
                'No encryption key set. Set APP_KEY in your .env file or re-run the setup wizard.'
            );
        }

        // Handle base64: prefix
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // Ensure key is correct length (32 bytes for AES-256)
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException(
                'Encryption key must be exactly 32 bytes for AES-256. '
                . 'Current key is ' . strlen($key) . ' bytes. '
                . 'Set a valid APP_KEY in your .env file or re-run the setup wizard.'
            );
        }

        return $key;
    }

    /**
     * Parse and validate the JSON payload
     */
    private static function getJsonPayload(string $payload): array
    {
        $payload = json_decode(base64_decode($payload), true);

        if (!is_array($payload) || !isset($payload['iv'], $payload['tag'], $payload['value'], $payload['mac'])) {
            throw new \RuntimeException('Invalid encryption payload');
        }

        return $payload;
    }

    /**
     * Verify the MAC of a payload
     */
    private static function validMac(array $payload, string $data): bool
    {
        return hash_equals(self::hash($data), $payload['mac']);
    }

    /**
     * Reset state (for testing)
     */
    public static function reset(): void
    {
        self::$key = '';
        self::$cipher = 'aes-256-gcm';
    }
}
