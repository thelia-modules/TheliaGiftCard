<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AddressQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\AddressTableMap;
use Thelia\Model\Map\CountryTableMap;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;
use TheliaGiftCard\Model\GiftCard;

/**
 * The people and references the back-office card list shows next to the ids of the cards of a page:
 * one query per table, whatever the number of cards.
 */
final readonly class GiftCardListInfoProvider
{
    /**
     * @param list<GiftCard> $giftCards the cards of the page
     *
     * @return array{orders: array<int, string>, sponsor_customers: array<int, array{name: string, email: string, country: string|null}>, beneficiary_customers: array<int, array{name: string, email: string, country: string|null}>, languages: list<array{locale: string, code: string, title: string}>}
     */
    public function build(array $giftCards): array
    {
        $orderIds = $this->ids($giftCards, static fn (GiftCard $giftCard): ?int => $giftCard->getOrderId());
        $sponsorIds = $this->ids($giftCards, static fn (GiftCard $giftCard): ?int => $giftCard->getSponsorCustomerId());
        $beneficiaryIds = $this->ids($giftCards, static fn (GiftCard $giftCard): ?int => $giftCard->getBeneficiaryCustomerId());
        $customers = $this->customers(array_values(array_unique([...$sponsorIds, ...$beneficiaryIds])));

        return [
            'orders' => $this->orderReferences($orderIds),
            'sponsor_customers' => array_intersect_key($customers, array_flip($sponsorIds)),
            'beneficiary_customers' => array_intersect_key($customers, array_flip($beneficiaryIds)),
            'languages' => $this->languages(),
        ];
    }

    /**
     * @param list<GiftCard>                 $giftCards
     * @param callable(GiftCard): (int|null) $idOf
     *
     * @return list<int>
     */
    private function ids(array $giftCards, callable $idOf): array
    {
        $ids = array_filter(array_map($idOf, $giftCards));

        return array_values(array_unique($ids));
    }

    /**
     * The name and email of each customer, and the country of their default address for the flag the
     * order list shows before a name.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, array{name: string, email: string, country: string|null}>
     */
    private function customers(array $customerIds): array
    {
        if ([] === $customerIds) {
            return [];
        }

        $countries = [];
        $addresses = AddressQuery::create()
            ->filterByCustomerId($customerIds, Criteria::IN)
            ->filterByIsDefault(1)
            ->useCountryQuery()
            ->endUse()
            ->select([AddressTableMap::COL_CUSTOMER_ID, CountryTableMap::COL_ISOALPHA2])
            ->find();

        foreach ($addresses as $address) {
            $countries[(int) $address[AddressTableMap::COL_CUSTOMER_ID]] = (string) $address[CountryTableMap::COL_ISOALPHA2];
        }

        $customers = [];
        $rows = CustomerQuery::create()
            ->filterById($customerIds, Criteria::IN)
            ->select([CustomerTableMap::COL_ID, CustomerTableMap::COL_FIRSTNAME, CustomerTableMap::COL_LASTNAME, CustomerTableMap::COL_EMAIL])
            ->find();

        foreach ($rows as $row) {
            $id = (int) $row[CustomerTableMap::COL_ID];
            $customers[$id] = [
                'name' => trim($row[CustomerTableMap::COL_FIRSTNAME].' '.$row[CustomerTableMap::COL_LASTNAME]),
                'email' => (string) $row[CustomerTableMap::COL_EMAIL],
                'country' => $countries[$id] ?? null,
            ];
        }

        return $customers;
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
