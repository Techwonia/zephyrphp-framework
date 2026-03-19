<?php

declare(strict_types=1);

namespace ZephyrPHP\OAuth;

/**
 * OAuth 2.0 Manager — handles authorization code flow.
 *
 * Flow:
 * 1. Client redirects user to /oauth/authorize?client_id=X&redirect_uri=Y&scope=Z&state=S
 * 2. User approves → server generates auth code, redirects to redirect_uri?code=C&state=S
 * 3. Client exchanges code for tokens: POST /oauth/token {grant_type, code, client_id, client_secret}
 * 4. Server returns {access_token, refresh_token, expires_in, scope}
 *
 * Security:
 * - Auth codes expire in 10 minutes, single-use
 * - Access tokens expire in 1 hour
 * - Refresh tokens expire in 30 days
 * - All secrets hashed with SHA-256
 * - State parameter prevents CSRF
 * - Redirect URI must match registered URI exactly
 * - PKCE support via code_challenge/code_verifier (S256)
 */
class OAuthManager
{
    private const AUTH_CODE_TTL = 600;           // 10 minutes
    private const ACCESS_TOKEN_TTL = 3600;       // 1 hour
    private const REFRESH_TOKEN_TTL = 2592000;   // 30 days

    /**
     * Available OAuth scopes.
     */
    public const SCOPES = [
        'read_pages'       => 'Read pages and page types',
        'write_pages'      => 'Create and update pages',
        'read_collections' => 'Read collections and entries',
        'write_collections'=> 'Create and update collection entries',
        'read_themes'      => 'Read theme information',
        'read_media'       => 'Read media files',
        'write_media'      => 'Upload media files',
        'manage_settings'  => 'Read and update settings',
        'read_users'       => 'Read user information',
    ];

    /**
     * Generate an authorization code for the given client + user.
     */
    public function createAuthCode(
        string $clientId,
        int $userId,
        array $scopes,
        string $redirectUri,
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'S256'
    ): string {
        $code = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::AUTH_CODE_TTL);

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->insert('cms_oauth_auth_codes', [
            'code' => hash('sha256', $code),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => json_encode($scopes),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => $expiresAt,
            'used' => 0,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        return $code;
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string, scope: string}|array{error: string}
     */
    public function exchangeCode(
        string $code,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        ?string $codeVerifier = null
    ): array {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

        // Find auth code
        $row = $conn->fetchAssociative(
            'SELECT * FROM cms_oauth_auth_codes WHERE code = ? AND used = 0',
            [hash('sha256', $code)]
        );

        if (!$row) {
            return ['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or already used.'];
        }

        // Check expiry
        if (strtotime($row['expires_at']) < time()) {
            return ['error' => 'invalid_grant', 'error_description' => 'Authorization code has expired.'];
        }

        // Verify client
        $client = OAuthClient::findByClientId($clientId);
        if (!$client || !$client->isActive()) {
            return ['error' => 'invalid_client', 'error_description' => 'Client not found or inactive.'];
        }

        if (!$client->verifySecret($clientSecret)) {
            return ['error' => 'invalid_client', 'error_description' => 'Invalid client secret.'];
        }

        if ($row['client_id'] !== $clientId) {
            return ['error' => 'invalid_grant', 'error_description' => 'Client ID mismatch.'];
        }

        if ($row['redirect_uri'] !== $redirectUri) {
            return ['error' => 'invalid_grant', 'error_description' => 'Redirect URI mismatch.'];
        }

        // PKCE verification
        if (!empty($row['code_challenge'])) {
            if (!$codeVerifier) {
                return ['error' => 'invalid_grant', 'error_description' => 'Code verifier required.'];
            }

            $method = $row['code_challenge_method'] ?? 'S256';
            $expectedChallenge = $method === 'S256'
                ? rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=')
                : $codeVerifier;

            if (!hash_equals($row['code_challenge'], $expectedChallenge)) {
                return ['error' => 'invalid_grant', 'error_description' => 'Code verifier mismatch.'];
            }
        }

        // Mark code as used
        $conn->update('cms_oauth_auth_codes', ['used' => 1], ['id' => $row['id']]);

        // Generate tokens
        $scopes = json_decode($row['scopes'], true) ?: [];
        $accessToken = $this->createAccessToken((int) $row['user_id'], $clientId, $scopes);
        $refreshToken = $this->createRefreshToken((int) $row['user_id'], $clientId, $scopes);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     */
    public function refreshAccessToken(string $refreshToken, string $clientId, string $clientSecret): array
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

        $row = $conn->fetchAssociative(
            'SELECT * FROM cms_oauth_tokens WHERE token = ? AND type = ? AND revoked = 0',
            [hash('sha256', $refreshToken), 'refresh']
        );

        if (!$row) {
            return ['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or revoked.'];
        }

        if (strtotime($row['expires_at']) < time()) {
            return ['error' => 'invalid_grant', 'error_description' => 'Refresh token has expired.'];
        }

        $client = OAuthClient::findByClientId($clientId);
        if (!$client || !$client->verifySecret($clientSecret)) {
            return ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials.'];
        }

        if ($row['client_id'] !== $clientId) {
            return ['error' => 'invalid_grant', 'error_description' => 'Client ID mismatch.'];
        }

        // Revoke old refresh token
        $conn->update('cms_oauth_tokens', ['revoked' => 1], ['id' => $row['id']]);

        // Issue new tokens
        $scopes = json_decode($row['scopes'], true) ?: [];
        $newAccess = $this->createAccessToken((int) $row['user_id'], $clientId, $scopes);
        $newRefresh = $this->createRefreshToken((int) $row['user_id'], $clientId, $scopes);

        return [
            'access_token' => $newAccess,
            'refresh_token' => $newRefresh,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Validate an access token and return its payload.
     *
     * @return array{user_id: int, client_id: string, scopes: string[]}|null
     */
    public function validateAccessToken(string $token): ?array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $row = $conn->fetchAssociative(
                'SELECT * FROM cms_oauth_tokens WHERE token = ? AND type = ? AND revoked = 0',
                [hash('sha256', $token), 'access']
            );

            if (!$row) return null;

            if (strtotime($row['expires_at']) < time()) return null;

            return [
                'user_id' => (int) $row['user_id'],
                'client_id' => $row['client_id'],
                'scopes' => json_decode($row['scopes'], true) ?: [],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Revoke all tokens for a client.
     */
    public function revokeClientTokens(string $clientId): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->update('cms_oauth_tokens', ['revoked' => 1], ['client_id' => $clientId]);
    }

    /**
     * Validate requested scopes against available + client scopes.
     *
     * @return string[] Valid scopes
     */
    public function validateScopes(array $requestedScopes, OAuthClient $client): array
    {
        $valid = [];
        foreach ($requestedScopes as $scope) {
            if (isset(self::SCOPES[$scope]) && $client->hasScope($scope)) {
                $valid[] = $scope;
            }
        }
        return $valid;
    }

    // ========================================================================
    // TOKEN GENERATION
    // ========================================================================

    private function createAccessToken(int $userId, string $clientId, array $scopes): string
    {
        return $this->storeToken($userId, $clientId, $scopes, 'access', self::ACCESS_TOKEN_TTL);
    }

    private function createRefreshToken(int $userId, string $clientId, array $scopes): string
    {
        return $this->storeToken($userId, $clientId, $scopes, 'refresh', self::REFRESH_TOKEN_TTL);
    }

    private function storeToken(int $userId, string $clientId, array $scopes, string $type, int $ttl): string
    {
        $plain = bin2hex(random_bytes(32));

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->insert('cms_oauth_tokens', [
            'token' => hash('sha256', $plain),
            'type' => $type,
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => json_encode($scopes),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'revoked' => 0,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        return $plain;
    }
}
