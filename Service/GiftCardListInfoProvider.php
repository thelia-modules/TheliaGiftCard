<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CustomerQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

/**
 * The names and references the back-office card list shows next to the ids: one query per
 * table, whatever the number of cards.
 */
final readonly class GiftCardListInfoProvider
{
    /**
     * @return array{orders: array<int, string>, sponsor_customers: array<int, string>, beneficiary_customers: array<int, string>, languages: list<array{locale: string, code: string, title: string}>}
     */
    public function build(): array
    {
        $links = GiftCardQuery::create()
            ->select([GiftCardTableMap::COL_ORDER_ID, GiftCardTableMap::COL_SPONSOR_CUSTOMER_ID, GiftCardTableMap::COL_BENEFICIARY_CUSTOMER_ID])
            ->find()
            ->toArray();

        $orderIds = $this->ids($links, GiftCardTableMap::COL_ORDER_ID);
        $sponsorIds = $this->ids($links, GiftCardTableMap::COL_SPONSOR_CUSTOMER_ID);
        $beneficiaryIds = $this->ids($links, GiftCardTableMap::COL_BENEFICIARY_CUSTOMER_ID);
        $customerNames = $this->customerNames(array_values(array_unique([...$sponsorIds, ...$beneficiaryIds])));

        return [
            'orders' => $this->orderReferences($orderIds),
            'sponsor_customers' => array_intersect_key($customerNames, array_flip($sponsorIds)),
            'beneficiary_customers' => array_intersect_key($customerNames, array_flip($beneficiaryIds)),
            'languages' => $this->languages(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $links
     *
     * @return list<int>
     */
    private function ids(array $links, string $column): array
    {
        $ids = array_filter(array_map(static fn (array $link): int => (int) ($link[$column] ?? 0), $links));

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $customerIds
     *
     * @return array<int, string>
     */
    private function customerNames(array $customerIds): array
    {
        if ([] === $customerIds) {
            return [];
        }

        $names = [];
        $rows = CustomerQuery::create()
            ->filterById($customerIds, Criteria::IN)
            ->select([CustomerTableMap::COL_ID, CustomerTableMap::COL_FIRSTNAME, CustomerTableMap::COL_LASTNAME])
            ->find();

        foreach ($rows as $row) {
            $names[(int) $row[CustomerTableMap::COL_ID]] = trim($row[CustomerTableMap::COL_FIRSTNAME].' '.$row[CustomerTableMap::COL_LASTNAME]);
        }

        return $names;
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, string>
     */
    private function orderReferences(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        $references = [];
        $rows = OrderQuery::create()
            ->filterById($orderIds, Criteria::IN)
            ->select([OrderTableMap::COL_ID, OrderTableMap::COL_REF])
            ->find();

        foreach ($rows as $row) {
            $references[(int) $row[OrderTableMap::COL_ID]] = (string) $row[OrderTableMap::COL_REF];
        }

        return $references;
    }

    /**
     * @return list<array{locale: string, code: string, title: string}>
     */
    private function languages(): array
    {
        $languages = [];

        foreach (LangQuery::create()->filterByActive(1)->find() as $language) {
            $languages[] = [
                'locale' => (string) $language->getLocale(),
                'code' => (string) $language->getCode(),
                'title' => (string) $language->getTitle(),
            ];
        }

        return $languages;
    }
}
