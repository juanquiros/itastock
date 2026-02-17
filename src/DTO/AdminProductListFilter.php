<?php

namespace App\DTO;

class AdminProductListFilter
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_LIMIT = 25;
    private const ALLOWED_LIMITS = [25, 50, 100];
    private const ALLOWED_SORTS = ['name', 'sku', 'barcode', 'basePrice', 'stock', 'stockMin', 'isActive', 'updatedAt'];

    public function __construct(
        private int $page = self::DEFAULT_PAGE,
        private int $limit = self::DEFAULT_LIMIT,
        private string $q = '',
        private string $sort = 'name',
        private string $dir = 'asc',
    ) {
        $this->page = max(self::DEFAULT_PAGE, $this->page);
        $this->limit = in_array($this->limit, self::ALLOWED_LIMITS, true) ? $this->limit : self::DEFAULT_LIMIT;
        $this->q = trim($this->q);
        $this->sort = in_array($this->sort, self::ALLOWED_SORTS, true) ? $this->sort : 'name';
        $this->dir = strtolower($this->dir) === 'desc' ? 'desc' : 'asc';
    }

    public static function fromQuery(array $query): self
    {
        return new self(
            isset($query['page']) ? (int) $query['page'] : self::DEFAULT_PAGE,
            isset($query['limit']) ? (int) $query['limit'] : self::DEFAULT_LIMIT,
            (string) ($query['q'] ?? ''),
            (string) ($query['sort'] ?? 'name'),
            (string) ($query['dir'] ?? 'asc'),
        );
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getQ(): string
    {
        return $this->q;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'q' => $this->q,
            'sort' => $this->sort,
            'dir' => $this->dir,
        ];
    }
}
