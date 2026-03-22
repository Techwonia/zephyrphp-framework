<?php

declare(strict_types=1);

namespace ZephyrPHP\Security;

/**
 * TOTP (Time-Based One-Time Password) Implementation
 *
 * Pure PHP implementation of RFC 6238 TOTP algorithm.
 * Compatible with Google Authenticator, Authy, and other TOTP apps.
 *
 * Usage:
 *   // Generate a secret
 *   $secret = Totp::generateSecret();
 *
 *   // Get otpauth:// URI for QR code
 *   $uri = Totp::getUri($secret, 'user@example.com');
 *
 *   // Verify a code from the user
 *   if (Totp::verify($secret, $userCode)) { ... }
 */
class Totp
{
    /** @var int Number of digits in the TOTP code */
    private const DIGITS = 6;

    /** @var int Time period in seconds */
    private const PERIOD = 30;

    /** @var string HMAC algorithm */
    private const ALGORITHM = 'sha1';

    /** @var int Number of time steps to allow for clock drift (±1) */
    private const WINDOW = 1;

    /** @var string Base32 alphabet */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random base32-encoded secret (20 bytes / 160 bits)
     *
     * @return string Base32-encoded secret
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return self::base32Encode($bytes);
    }

    /**
     * Generate a TOTP code for a given secret and time
     *
     * @param string $secret Base32-encoded secret
     * @param int|null $time Unix timestamp (defaults to current time)
     * @return string 6-digit TOTP code (zero-padded)
     */
    public static function generate(string $secret, ?int $time = null): string
    {
        $time = $time ?? time();
        $timeStep = (int) floor($time / self::PERIOD);

        // Convert time step to 8-byte big-endian binary
        $timeBytes = pack('N*', 0, $timeStep);

        // Decode the base32 secret to raw bytes
        $secretBytes = self::base32Decode($secret);

        // HMAC-SHA1
        $hash = hash_hmac(self::ALGORITHM, $timeBytes, $secretBytes, true);

        // Dynamic truncation (RFC 4226 section 5.4)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code with clock drift tolerance
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $secret Base32-encoded secret
     * @param string $code User-provided TOTP code
     * @return bool True if the code is valid
     */
    public static function verify(string $secret, string $code): bool
    {
        // Sanitize: only allow digits, must be exactly DIGITS length
        $code = trim($code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $time = time();

        // Check current time step and ±WINDOW steps for clock drift
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $checkTime = $time + ($i * self::PERIOD);
            $expected = self::generate($secret, $checkTime);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an otpauth:// URI for authenticator apps
     *
     * @param string $secret Base32-encoded secret
     * @param string $email User's email address (used as account label)
     * @param string $issuer Application name
     * @return string otpauth:// URI
     */
    public static function getUri(string $secret, string $email, string $issuer = 'ZephyrPHP'): string
    {
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        $label = rawurlencode($issuer) . ':' . rawurlencode($email);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Generate recovery codes
     *
     * @param int $count Number of codes to generate
     * @return array Array of recovery codes in XXXX-XXXX format
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789'; // Exclude ambiguous chars (i, l, o, 0, 1)

        for ($i = 0; $i < $count; $i++) {
            $part1 = '';
            $part2 = '';

            for ($j = 0; $j < 4; $j++) {
                $part1 .= $chars[random_int(0, strlen($chars) - 1)];
                $part2 .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $codes[] = $part1 . '-' . $part2;
        }

        return $codes;
    }

    /**
     * Base32 encode binary data
     *
     * @param string $data Raw binary data
     * @return string Base32-encoded string
     */
    private static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);

        foreach ($chunks as $chunk) {
            // Pad the last chunk to 5 bits if necessary
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Base32 decode a string to binary data
     *
     * @param string $base32 Base32-encoded string
     * @return string Raw binary data
     * @throws \InvalidArgumentException If the string contains invalid characters
     */
    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));

        if (empty($base32)) {
            return '';
        }

        // Validate characters
        if (preg_match('/[^A-Z2-7]/', $base32)) {
            throw new \InvalidArgumentException('Invalid base32 character in secret');
        }

        $binary = '';
        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $chunks = str_split($binary, 8);

        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
