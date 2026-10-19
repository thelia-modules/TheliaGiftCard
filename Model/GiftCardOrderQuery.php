<?php

declare(strict_types=1);

namespace TheliaGiftCard\Model;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaGiftCard\Model\Base\GiftCardOrderQuery as BaseGiftCardOrderQuery;
use TheliaGiftCard\Model\Map\GiftCardOrderTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;

/**
 * Skeleton subclass for performing query and update operations on the 'gift_card_order' table.
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 */
class GiftCardOrderQuery extends BaseGiftCardOrderQuery
{
    /**
     * What an order spent from a card: status 0, or no status at all (the column is nullable: a row
     * imported by SQL or migrated from Thelia 2 has none, and the release credits it as spent).
     * Every reader of "what the order spent" goes through here, so that none disagrees with the release.
     */
    public function filterBySpent(): static
    {
        return $this
            ->condition('spent', GiftCardOrderTableMap::COL_STATUS.' = ?', GiftCardPaymentService::STATUS_SPENT)
            ->condition('unset', GiftCardOrderTableMap::COL_STATUS.' IS NULL')
            ->where(['spent', 'unset'], Criteria::LOGICAL_OR);
    }
}
