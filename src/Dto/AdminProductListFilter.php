<?php

namespace App\Dto;

class AdminProductListFilter
{
    private const ALLOWED_LIMITS = [25, 50, 100];
    private const ALLOWED_SORTS = ['name', 'sku', 'stock', 'updatedAt'];
    private const ALLOWED_DIRS = ['asc', 'desc'];

    public function __construct(
        private readonly int $page,
        private readonly int $limit,
        private readonly string $q,
        private readonly string $sort,
        private readonly string $dir,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $page = max(1, (int) ($query['page'] ?? 1));

        $rawLimit = (int) ($query['limit'] ?? 25);
        $limit = in_array($rawLimit, self::ALLOWED_LIMITS, true) ? $rawLimit : 25;

        $q = trim((string) ($query['q'] ?? ''));

        $sort = (string) ($query['sort'] ?? 'name');
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'name';
        }

        $dir = strtolower((string) ($query['dir'] ?? 'asc'));
        if (!in_array($dir, self::ALLOWED_DIRS, true)) {
            $dir = 'asc';
        }

        return new self($page, $limit, $q, $sort, $dir);
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
}
