<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Presenter;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaGiftCard\Dto\GiftCardListCriteria;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The search, the type and status chips and the links of the list: each chip links to the same list
 * with its own filter changed and the rest kept.
 */
final readonly class GiftCardListFilterPresenter
{
    public const ROUTE = 'admin.module.configure';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return array{search: string, sort: string, direction: string, type_chips: list<array{code: string, url: string, active: bool}>, status_chips: list<array{code: string, url: string, active: bool}>, reset_url: string, query_params: array<string, scalar>, list_query: string}
     */
    public function present(GiftCardListCriteria $criteria): array
    {
        return [
            'search' => $criteria->search,
            'sort' => $criteria->sort,
            'direction' => $criteria->direction,
            'type_chips' => $this->chips(GiftCardListCriteria::TYPES, 'type', $criteria->type, $criteria),
            'status_chips' => $this->chips(GiftCardListCriteria::STATUSES, 'status', $criteria->status, $criteria),
            'reset_url' => $this->url([]),
            // What a sort header or a page link keeps: the search and the filters, never the page.
            'query_params' => $criteria->toQuery(),
            // What an action sent from the list returns to: the very same page of the list.
            'list_query' => http_build_query($criteria->toQuery(withPage: true)),
        ];
    }

    /**
     * @param list<string> $codes
     *
     * @return list<array{code: string, url: string, active: bool}>
     */
    private function chips(array $codes, string $parameter, string $current, GiftCardListCriteria $criteria): array
    {
        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'url' => $this->url($criteria->toQuery([$parameter => 'all' === $code ? '' : $code])),
                'active' => $code === $current,
            ],
            $codes,
        );
    }

    /**
     * @param array<string, scalar> $query
     */
    private function url(array $query): string
    {
        return $this->urls->generate(self::ROUTE, ['module_code' => TheliaGiftCard::MODULE_CODE] + $query);
    }
}
