<?php

declare(strict_types=1);

namespace ZephyrPHP\Support;

/**
 * Simple paginator for query results.
 *
 * Usage:
 *   $paginator = new Paginator($items, $total, $perPage, $currentPage);
 *   $paginator->items();      // Current page items
 *   $paginator->links();      // HTML pagination links
 *   $paginator->toArray();    // Full pagination data
 */
class Paginator implements \JsonSerializable
{
    protected array $items;
    protected int $total;
    protected int $perPage;
    protected int $currentPage;
    protected int $lastPage;
    protected string $path;
    protected string $pageParam;

    public function __construct(
        array $items,
        int $total,
        int $perPage = 15,
        ?int $currentPage = null,
        string $path = '',
        string $pageParam = 'page'
    ) {
        $this->items = $items;
        $this->total = max(0, $total);
        $this->perPage = max(1, $perPage);
        $this->currentPage = max(1, $currentPage ?? (int) ($_GET[$pageParam] ?? 1));
        $this->lastPage = max(1, (int) ceil($this->total / $this->perPage));
        $this->path = $path ?: strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $this->pageParam = $pageParam;
    }

    /**
     * Create a paginator from a full dataset (auto-slices).
     */
    public static function fromArray(array $items, int $perPage = 15, ?int $currentPage = null): self
    {
        $page = max(1, $currentPage ?? (int) ($_GET['page'] ?? 1));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($items, $offset, $perPage);

        return new self($sliced, $total, $perPage, $page);
    }

    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    public function previousPageUrl(): ?string
    {
        if ($this->onFirstPage()) {
            return null;
        }
        return $this->url($this->currentPage - 1);
    }

    public function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }
        return $this->url($this->currentPage + 1);
    }

    /** @var array|null Allowlist of query parameter names to preserve in pagination URLs */
    protected ?array $allowedQueryParams = null;

    /**
     * Set the allowed query parameter names for pagination URLs.
     * Only these parameters (plus the page param) will be carried forward.
     */
    public function setAllowedQueryParams(array $params): self
    {
        $this->allowedQueryParams = $params;
        return $this;
    }

    public function url(int $page): string
    {
        $page = max(1, $page);
        $query = $_GET ?? [];

        // If an explicit allowlist is set, filter to only those keys
        if ($this->allowedQueryParams !== null) {
            $allowed = array_flip($this->allowedQueryParams);
            $query = array_intersect_key($query, $allowed);
        }

        $query[$this->pageParam] = $page;

        // http_build_query encodes values, preventing reflected XSS
        return $this->path . '?' . http_build_query($query);
    }

    /**
     * Get the range of pages to display (window around current page).
     */
    public function pageRange(int $window = 2): array
    {
        $start = max(1, $this->currentPage - $window);
        $end = min($this->lastPage, $this->currentPage + $window);

        // Ensure minimum window size
        if ($end - $start < $window * 2) {
            $start = max(1, $end - $window * 2);
            $end = min($this->lastPage, $start + $window * 2);
        }

        return range($start, $end);
    }

    /**
     * Generate simple HTML pagination links.
     */
    public function links(string $class = 'pagination'): string
    {
        if (!$this->hasPages()) {
            return '';
        }

        $html = '<nav><ul class="' . htmlspecialchars($class) . '">';

        // Previous
        if ($this->onFirstPage()) {
            $html .= '<li class="disabled"><span>&laquo;</span></li>';
        } else {
            $html .= '<li><a href="' . htmlspecialchars($this->previousPageUrl()) . '">&laquo;</a></li>';
        }

        // Page numbers
        foreach ($this->pageRange() as $page) {
            if ($page === $this->currentPage) {
                $html .= '<li class="active"><span>' . $page . '</span></li>';
            } else {
                $html .= '<li><a href="' . htmlspecialchars($this->url($page)) . '">' . $page . '</a></li>';
            }
        }

        // Next
        if ($this->onLastPage()) {
            $html .= '<li class="disabled"><span>&raquo;</span></li>';
        } else {
            $html .= '<li><a href="' . htmlspecialchars($this->nextPageUrl()) . '">&raquo;</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'from' => $this->total > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null,
            'to' => $this->total > 0 ? min($this->currentPage * $this->perPage, $this->total) : null,
            'prev_page_url' => $this->previousPageUrl(),
            'next_page_url' => $this->nextPageUrl(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
