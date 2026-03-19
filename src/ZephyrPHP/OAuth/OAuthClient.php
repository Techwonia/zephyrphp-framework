<?php

declare(strict_types=1);

namespace ZephyrPHP\OAuth;

/**
 * Represents a registered OAuth 2.0 client (external app).
 * Stored in cms_oauth_clients table.
 */
class OAuthClient
{
    private int $id = 0;
    private string $name = '';
    private string $clientId = '';
    private string $clientSecret = '';        // hashed
    private string $redirectUri = '';
    private array $scopes = [];
    private bool $isActive = true;
    private ?string $createdAt = null;

    public static function findByClientId(string $clientId): ?self
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return null;

            $row = $conn->fetchAssociative(
                'SELECT * FROM cms_oauth_clients WHERE client_id = ?',
                [$clientId]
            );

            return $row ? self::fromRow($row) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function findById(int $id): ?self
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return null;

            $row = $conn->fetchAssociative(
                'SELECT * FROM cms_oauth_clients WHERE id = ?',
                [$id]
            );

            return $row ? self::fromRow($row) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function findAll(): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if (!$conn) return [];

            $rows = $conn->fetchAllAssociative('SELECT * FROM cms_oauth_clients ORDER BY name ASC');
            return array_map(fn($r) => self::fromRow($r), $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function create(string $name, string $redirectUri, array $scopes = []): self
    {
        $client = new self();
        $client->name = $name;
        $client->clientId = bin2hex(random_bytes(16));
        $plainSecret = bin2hex(random_bytes(32));
        $client->clientSecret = password_hash($plainSecret, PASSWORD_BCRYPT);
        $client->redirectUri = $redirectUri;
        $client->scopes = $scopes;

        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->insert('cms_oauth_clients', [
            'name' => $client->name,
            'client_id' => $client->clientId,
            'client_secret' => $client->clientSecret,
            'redirect_uri' => $client->redirectUri,
            'scopes' => json_encode($client->scopes),
            'is_active' => 1,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        $client->id = (int) $conn->lastInsertId();
        // Return plain secret once (caller must store it)
        $client->_plainSecret = $plainSecret;

        return $client;
    }

    public function delete(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $conn->delete('cms_oauth_clients', ['id' => $this->id]);
    }

    private static function fromRow(array $row): self
    {
        $client = new self();
        $client->id = (int) $row['id'];
        $client->name = $row['name'];
        $client->clientId = $row['client_id'];
        $client->clientSecret = $row['client_secret'];
        $client->redirectUri = $row['redirect_uri'];
        $client->scopes = json_decode($row['scopes'] ?? '[]', true) ?: [];
        $client->isActive = (bool) $row['is_active'];
        $client->createdAt = $row['createdAt'] ?? null;
        return $client;
    }

    /**
     * Verify a plain-text secret against the stored hash.
     */
    public function verifySecret(string $plainSecret): bool
    {
        return password_verify($plainSecret, $this->clientSecret);
    }

    /**
     * Check if the client has a given scope.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    // Transient property — only set on create(), cleared after first read
    private string $_plainSecret = '';

    /**
     * Get the plain-text secret (only available immediately after create()).
     * The value is cleared after the first read to minimise exposure.
     */
    public function getPlainSecret(): string
    {
        $secret = $this->_plainSecret;
        $this->_plainSecret = '';
        return $secret;
    }

    // Getters
    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getClientId(): string { return $this->clientId; }
    public function getRedirectUri(): string { return $this->redirectUri; }
    public function getScopes(): array { return $this->scopes; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
}
