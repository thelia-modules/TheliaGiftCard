<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaGiftCard\Dto\GiftCardListCriteria;
use TheliaGiftCard\Dto\GiftCardListPage;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

/**
 * The cards of the back-office list: filtered, searched, sorted and cut into pages in the query, so
 * a shop with thousands of cards reads one page of them.
 */
final readonly class GiftCardListService
{
    public const PAGE_SIZE = 25;

    private const SORT_COLUMNS = [
        'id' => GiftCardTableMap::COL_ID,
        'code' => GiftCardTableMap::COL_CODE,
        'amount' => GiftCardTableMap::COL_AMOUNT,
        'spend_amount' => GiftCardTableMap::COL_SPEND_AMOUNT,
        'expiration_date' => GiftCardTableMap::COL_EXPIRATION_DATE,
        'created_at' => GiftCardTableMap::COL_CREATED_AT,
        'status' => GiftCardTableMap::COL_STATUS,
    ];

    public function find(GiftCardListCriteria $criteria): GiftCardListPage
    {
        $query = $this->filteredQuery($criteria);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($criteria->page, $lastPage);

        $cards = $query
            ->orderBy(self::SORT_COLUMNS[$criteria->sort] ?? self::SORT_COLUMNS[GiftCardListCriteria::DEFAULT_SORT], 'asc' === $criteria->direction ? Criteria::ASC : Criteria::DESC)
            // Cards sharing a sort value keep the same order from one page to the next.
            ->orderBy(GiftCardTableMap::COL_ID, Criteria::DESC)
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->find();

        /** @var list<GiftCard> $pageCards */
        $pageCards = iterator_to_array($cards, false);

        return new GiftCardListPage($pageCards, $total, $page, $lastPage);
    }

    private function filteredQuery(GiftCardListCriteria $criteria): GiftCardQuery
    {
        $query = GiftCardQuery::create();

        // A card bought in the shop belongs to an order, a card made by an administrator does not.
        match ($criteria->type) {
            GiftCardListCriteria::TYPE_PURCHASED => $query->filterByOrderId(null, Criteria::ISNOTNULL),
            GiftCardListCriteria::TYPE_GENERATED => $query->filterByOrderId(null, Criteria::ISNULL),
            default => null,
        };

        match ($criteria->status) {
            GiftCardListCriteria::STATUS_ACTIVE => $query->filterByStatus(1),
            GiftCardListCriteria::STATUS_INACTIVE => $query->filterByStatus(0),
            default => null,
        };

        if ('' !== $criteria->search) {
            $this->search($query, $criteria->search);
        }

        return $query;
    }

    /**
     * The code, the reference of the order, and the name (first name and last name, in either order of
     * words) or the e-mail of the buyer or of the beneficiary: what the rows of the list show.
     */
    private function search(GiftCardQuery $query, string $search): void
    {
        $like = '%'.addcslashes($search, '\\%_').'%';

        $query
            ->joinOrder('searched_order')
            ->joinCustomerRelatedBySponsorCustomerId('searched_sponsor')
            ->joinCustomerRelatedByBeneficiaryCustomerId('searched_beneficiary')
            ->condition('by_code', 'GiftCard.Code LIKE ?', $like)
            ->condition('by_order', 'searched_order.Ref LIKE ?', $like)
            ->condition('by_sponsor', "CONCAT(searched_sponsor.Firstname, ' ', searched_sponsor.Lastname) LIKE ?", $like)
            ->condition('by_sponsor_reversed', "CONCAT(searched_sponsor.Lastname, ' ', searched_sponsor.Firstname) LIKE ?", $like)
            ->condition('by_beneficiary', "CONCAT(searched_beneficiary.Firstname, ' ', searched_beneficiary.Lastname) LIKE ?", $like)
            ->condition('by_beneficiary_reversed', "CONCAT(searched_beneficiary.Lastname, ' ', searched_beneficiary.Firstname) LIKE ?", $like)
            ->condition('by_sponsor_email', 'searched_sponsor.Email LIKE ?', $like)
            ->condition('by_beneficiary_email', 'searched_beneficiary.Email LIKE ?', $like)
            ->where(['by_code', 'by_order', 'by_sponsor', 'by_sponsor_reversed', 'by_beneficiary', 'by_beneficiary_reversed', 'by_sponsor_email', 'by_beneficiary_email'], Criteria::LOGICAL_OR);
    }
}
