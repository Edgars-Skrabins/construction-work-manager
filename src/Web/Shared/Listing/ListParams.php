<?php

declare(strict_types=1);

namespace App\Web\Shared\Listing;

use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;

final readonly class ListParams
{
    public const int SEARCH_MAX_LENGTH = 100;

    public function __construct(
        public string $sort,
        public int $page,
        public string $q = '',
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $query = $request->getQueryParams();
        $sort = is_string($query['sort'] ?? null) ? $query['sort'] : '';
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9]\d{0,8}$/', $query['page']) === 1
            ? (int) $query['page']
            : 1;
        $q = is_string($query['q'] ?? null) ? trim(mb_substr(trim($query['q']), 0, self::SEARCH_MAX_LENGTH)) : '';

        return new self($sort, $page, $q);
    }

    /**
     * @template TKey of array-key
     * @template TValue of array|object
     * @param OffsetPaginator<TKey, TValue> $paginator
     * @return OffsetPaginator<TKey, TValue>
     */
    public function applyTo(OffsetPaginator $paginator): OffsetPaginator
    {
        return $paginator->withCurrentPage(max(1, min($this->page, $paginator->getTotalPages())));
    }
}
