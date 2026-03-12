<?php

declare(strict_types=1);

namespace ZephyrPHP\Marketplace;

/**
 * Marketplace Review model — stores ratings and reviews for marketplace items.
 */
class MarketplaceReview
{
    private int $id = 0;
    private int $itemId = 0;
    private int $userId = 0;
    private string $userName = '';
    private int $rating = 5;
    private string $body = '';
    private ?string $createdAt = null;

    public static function findByItem(int $itemId, int $page = 1, int $perPage = 10): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $offset = ($page - 1) * $perPage;
            $rows = $conn->fetchAllAssociative(
                'SELECT * FROM cms_marketplace_reviews WHERE item_id = ? ORDER BY createdAt DESC LIMIT ? OFFSET ?',
                [$itemId, $perPage, $offset],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]
            );
            return array_map(fn($r) => self::fromRow($r), $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function hasReviewed(int $itemId, int $userId): bool
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            return (bool) $conn->fetchOne(
                'SELECT COUNT(*) FROM cms_marketplace_reviews WHERE item_id = ? AND user_id = ?',
                [$itemId, $userId]
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    public function save(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

        if ($this->id > 0) {
            $conn->update('cms_marketplace_reviews', [
                'rating' => $this->rating,
                'body' => $this->body,
            ], ['id' => $this->id]);
        } else {
            $conn->insert('cms_marketplace_reviews', [
                'item_id' => $this->itemId,
                'user_id' => $this->userId,
                'user_name' => $this->userName,
                'rating' => $this->rating,
                'body' => $this->body,
                'createdAt' => date('Y-m-d H:i:s'),
            ]);
            $this->id = (int) $conn->lastInsertId();
        }
    }

    private static function fromRow(array $row): self
    {
        $r = new self();
        $r->id = (int) $row['id'];
        $r->itemId = (int) $row['item_id'];
        $r->userId = (int) $row['user_id'];
        $r->userName = $row['user_name'] ?? '';
        $r->rating = (int) $row['rating'];
        $r->body = $row['body'] ?? '';
        $r->createdAt = $row['createdAt'] ?? null;
        return $r;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->userName,
            'rating' => $this->rating,
            'body' => $this->body,
            'created_at' => $this->createdAt,
        ];
    }

    // Setters
    public function setItemId(int $v): void { $this->itemId = $v; }
    public function setUserId(int $v): void { $this->userId = $v; }
    public function setUserName(string $v): void { $this->userName = $v; }
    public function setRating(int $v): void { $this->rating = max(1, min(5, $v)); }
    public function setBody(string $v): void { $this->body = $v; }

    // Getters
    public function getId(): int { return $this->id; }
    public function getRating(): int { return $this->rating; }
    public function getBody(): string { return $this->body; }
    public function getUserName(): string { return $this->userName; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
}
