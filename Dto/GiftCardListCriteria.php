<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Dto;

/**
 * What the back-office card list is asked for: the search, the filters, the sort and the page, as
 * they travel in the query string of the module page. Anything the list does not know falls back
 * to the default, so a query string can be read from a request or from a form field alike.
 */
final readonly class GiftCardListCriteria
{
    public const TYPE_ALL = 'all';
    public const TYPE_PURCHASED = 'purchased';
    public const TYPE_GENERATED = 'generated';
    public const TYPES = [self::TYPE_ALL, self::TYPE_PURCHASED, self::TYPE_GENERATED];

    public const STATUS_ALL = 'all';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUSES = [self::STATUS_ALL, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const SORTS = ['id', 'code', 'amount', 'spend_amount', 'expiration_date', 'created_at', 'status'];
    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';

    private const MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public string $search = '',
        public string $type = self::TYPE_ALL,
        public string $status = self::STATUS_ALL,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public int $page = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $search = $query['q'] ?? '';
        $type = $query['type'] ?? '';
        $status = $query['status'] ?? '';
        $sort = $query['order'] ?? '';
        $direction = $query['direction'] ?? '';
        $page = $query['page'] ?? 1;

        return new self(
            search: \is_string($search) ? mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH) : '',
            type: \is_string($type) && \in_array($type, self::TYPES, true) ? $type : self::TYPE_ALL,
            status: \is_string($status) && \in_array($status, self::STATUSES, true) ? $status : self::STATUS_ALL,
            sort: \is_string($sort) && \in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT,
            direction: 'asc' === $direction ? 'asc' : self::DEFAULT_DIRECTION,
            page: max(1, is_numeric($page) ? (int) $page : 1),
        );
    }

    /**
     * The query string of a list link: only what differs from the default, so the URL stays clean.
     * The page is left out unless asked for, as a link that changes the filters goes back to page 1.
     *
     * @param array<string, scalar> $overrides query parameters (`q`, `type`, `status`, `order`, `direction`, `page`) replacing the ones of this criteria
     *
     * @return array<string, scalar>
     */
    public function toQuery(array $overrides = [], bool $withPage = false): array
    {
        $query = [
            'q' => $this->search,
            'type' => self::TYPE_ALL === $this->type ? '' : $this->type,
            'status' => self::STATUS_ALL === $this->status ? '' : $this->status,
            'order' => self::DEFAULT_SORT === $this->sort ? '' : $this->sort,
            'direction' => self::DEFAULT_DIRECTION === $this->direction ? '' : $this->direction,
            'page' => $withPage && $this->page > 1 ? $this->page : '',
        ];

        return array_filter(
            array_merge($query, $overrides),
            static fn (mixed $value): bool => '' !== $value && null !== $value,
        );
    }

    public function isFiltered(): bool
    {
        return '' !== $this->search || self::TYPE_ALL !== $this->type || self::STATUS_ALL !== $this->status;
    }
}
