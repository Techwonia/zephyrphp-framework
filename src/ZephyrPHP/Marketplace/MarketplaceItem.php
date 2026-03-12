<?php

declare(strict_types=1);

namespace ZephyrPHP\Marketplace;

/**
 * Marketplace Item model — represents a theme, app, or section listed
 * on the marketplace. Stored in cms_marketplace_items table.
 */
class MarketplaceItem
{
    private int $id = 0;
    private string $slug = '';
    private string $name = '';
    private string $type = 'app';       // theme, app, section
    private string $category = '';
    private string $description = '';
    private string $version = '1.0.0';
    private int $sellerId = 0;
    private string $sellerName = '';
    private string $pricing = 'free';    // free, paid, subscription
    private int $priceInCents = 0;
    private string $currency = 'USD';
    private string $status = 'pending';  // pending, approved, rejected, published
    private string $packagePath = '';     // path to uploaded ZIP
    private ?string $previewImage = null;
    private array $screenshots = [];
    private int $downloads = 0;
    private float $avgRating = 0.0;
    private int $reviewCount = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    // ========================================================================
    // FINDERS
    // ========================================================================

    public static function findBySlug(string $slug): ?self
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $row = $conn->fetchAssociative(
                'SELECT * FROM cms_marketplace_items WHERE slug = ? AND status = ?',
                [$slug, 'published']
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
            $row = $conn->fetchAssociative('SELECT * FROM cms_marketplace_items WHERE id = ?', [$id]);
            return $row ? self::fromRow($row) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Browse items with filters and pagination.
     *
     * @return array{items: self[], total: int}
     */
    public static function browse(array $filters = []): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();

            $where = ['i.status = ?'];
            $params = ['published'];
            $types = [\Doctrine\DBAL\ParameterType::STRING];

            if (!empty($filters['type'])) {
                $where[] = 'i.type = ?';
                $params[] = $filters['type'];
                $types[] = \Doctrine\DBAL\ParameterType::STRING;
            }

            if (!empty($filters['category'])) {
                $where[] = 'i.category = ?';
                $params[] = $filters['category'];
                $types[] = \Doctrine\DBAL\ParameterType::STRING;
            }

            if (!empty($filters['search'])) {
                $where[] = '(i.name LIKE ? OR i.description LIKE ?)';
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
                $types[] = \Doctrine\DBAL\ParameterType::STRING;
                $types[] = \Doctrine\DBAL\ParameterType::STRING;
            }

            if (!empty($filters['seller_id'])) {
                $where[] = 'i.seller_id = ?';
                $params[] = (int) $filters['seller_id'];
                $types[] = \Doctrine\DBAL\ParameterType::INTEGER;
            }

            $whereClause = implode(' AND ', $where);

            // Sort
            $sort = match ($filters['sort'] ?? 'popular') {
                'newest' => 'i.createdAt DESC',
                'rating' => 'i.avg_rating DESC',
                'downloads' => 'i.downloads DESC',
                'name' => 'i.name ASC',
                default => 'i.downloads DESC',
            };

            // Count
            $total = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM cms_marketplace_items i WHERE {$whereClause}",
                $params,
                $types
            );

            // Paginate
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            $rows = $conn->fetchAllAssociative(
                "SELECT i.* FROM cms_marketplace_items i WHERE {$whereClause} ORDER BY {$sort} LIMIT {$perPage} OFFSET {$offset}",
                $params,
                $types
            );

            return [
                'items' => array_map(fn($r) => self::fromRow($r), $rows),
                'total' => $total,
            ];
        } catch (\Exception $e) {
            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * Find items by seller.
     */
    public static function findBySeller(int $sellerId): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $rows = $conn->fetchAllAssociative(
                'SELECT * FROM cms_marketplace_items WHERE seller_id = ? ORDER BY createdAt DESC',
                [$sellerId]
            );
            return array_map(fn($r) => self::fromRow($r), $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    // ========================================================================
    // PERSISTENCE
    // ========================================================================

    public function save(): void
    {
        $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
        $data = [
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'version' => $this->version,
            'seller_id' => $this->sellerId,
            'seller_name' => $this->sellerName,
            'pricing' => $this->pricing,
            'price_in_cents' => $this->priceInCents,
            'currency' => $this->currency,
            'status' => $this->status,
            'package_path' => $this->packagePath,
            'preview_image' => $this->previewImage,
            'screenshots' => json_encode($this->screenshots),
            'downloads' => $this->downloads,
            'avg_rating' => $this->avgRating,
            'review_count' => $this->reviewCount,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        if ($this->id > 0) {
            $conn->update('cms_marketplace_items', $data, ['id' => $this->id]);
        } else {
            $data['createdAt'] = date('Y-m-d H:i:s');
            $conn->insert('cms_marketplace_items', $data);
            $this->id = (int) $conn->lastInsertId();
        }
    }

    public function delete(): void
    {
        if ($this->id > 0) {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $conn->delete('cms_marketplace_items', ['id' => $this->id]);
        }
    }

    public function incrementDownloads(): void
    {
        if ($this->id > 0) {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $conn->executeStatement(
                'UPDATE cms_marketplace_items SET downloads = downloads + 1 WHERE id = ?',
                [$this->id]
            );
            $this->downloads++;
        }
    }

    public function updateRating(): void
    {
        if ($this->id > 0) {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $row = $conn->fetchAssociative(
                'SELECT AVG(rating) as avg_r, COUNT(*) as cnt FROM cms_marketplace_reviews WHERE item_id = ?',
                [$this->id]
            );
            if ($row) {
                $this->avgRating = round((float) ($row['avg_r'] ?? 0), 1);
                $this->reviewCount = (int) ($row['cnt'] ?? 0);
                $conn->update('cms_marketplace_items', [
                    'avg_rating' => $this->avgRating,
                    'review_count' => $this->reviewCount,
                ], ['id' => $this->id]);
            }
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private static function fromRow(array $row): self
    {
        $item = new self();
        $item->id = (int) $row['id'];
        $item->slug = $row['slug'] ?? '';
        $item->name = $row['name'] ?? '';
        $item->type = $row['type'] ?? 'app';
        $item->category = $row['category'] ?? '';
        $item->description = $row['description'] ?? '';
        $item->version = $row['version'] ?? '1.0.0';
        $item->sellerId = (int) ($row['seller_id'] ?? 0);
        $item->sellerName = $row['seller_name'] ?? '';
        $item->pricing = $row['pricing'] ?? 'free';
        $item->priceInCents = (int) ($row['price_in_cents'] ?? 0);
        $item->currency = $row['currency'] ?? 'USD';
        $item->status = $row['status'] ?? 'pending';
        $item->packagePath = $row['package_path'] ?? '';
        $item->previewImage = $row['preview_image'] ?? null;
        $item->screenshots = json_decode($row['screenshots'] ?? '[]', true) ?: [];
        $item->downloads = (int) ($row['downloads'] ?? 0);
        $item->avgRating = (float) ($row['avg_rating'] ?? 0);
        $item->reviewCount = (int) ($row['review_count'] ?? 0);
        $item->createdAt = $row['createdAt'] ?? null;
        $item->updatedAt = $row['updatedAt'] ?? null;
        return $item;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'version' => $this->version,
            'seller_name' => $this->sellerName,
            'pricing' => $this->pricing,
            'price' => $this->priceInCents > 0 ? number_format($this->priceInCents / 100, 2) : 'Free',
            'currency' => $this->currency,
            'preview_image' => $this->previewImage,
            'screenshots' => $this->screenshots,
            'downloads' => $this->downloads,
            'avg_rating' => $this->avgRating,
            'review_count' => $this->reviewCount,
            'created_at' => $this->createdAt,
        ];
    }

    // Getters & Setters
    public function getId(): int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): void { $this->slug = $v; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = $v; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): void { $this->category = $v; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): void { $this->description = $v; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $v): void { $this->version = $v; }
    public function getSellerId(): int { return $this->sellerId; }
    public function setSellerId(int $v): void { $this->sellerId = $v; }
    public function getSellerName(): string { return $this->sellerName; }
    public function setSellerName(string $v): void { $this->sellerName = $v; }
    public function getPricing(): string { return $this->pricing; }
    public function setPricing(string $v): void { $this->pricing = $v; }
    public function getPriceInCents(): int { return $this->priceInCents; }
    public function setPriceInCents(int $v): void { $this->priceInCents = $v; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getPackagePath(): string { return $this->packagePath; }
    public function setPackagePath(string $v): void { $this->packagePath = $v; }
    public function getPreviewImage(): ?string { return $this->previewImage; }
    public function setPreviewImage(?string $v): void { $this->previewImage = $v; }
    public function getScreenshots(): array { return $this->screenshots; }
    public function setScreenshots(array $v): void { $this->screenshots = $v; }
    public function getDownloads(): int { return $this->downloads; }
    public function getAvgRating(): float { return $this->avgRating; }
    public function getReviewCount(): int { return $this->reviewCount; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function isPaid(): bool { return $this->pricing !== 'free'; }
    public function getFormattedPrice(): string
    {
        if ($this->pricing === 'free') return 'Free';
        return '$' . number_format($this->priceInCents / 100, 2);
    }
}
