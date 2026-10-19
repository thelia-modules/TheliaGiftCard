<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Loop;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\Map\GiftCardOrderTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;

class GiftCardOrderList extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntListTypeArgument('order_id'),
            // 0 (the default): what the order spent; 1: what was given back to the cards. STATUS says which.
            Argument::createIntListTypeArgument('status', (string) GiftCardPaymentService::STATUS_SPENT),
        );
    }

    public function buildModelCriteria(): ModelCriteria
    {
        $query = GiftCardOrderQuery::create()->filterByOrderId($this->getOrderId());
        $statuses = array_map('intval', (array) $this->getStatus());

        if (!\in_array(GiftCardPaymentService::STATUS_SPENT, $statuses, true)) {
            return $query->filterByStatus($statuses);
        }

        // A row with no status counts as spent, like everywhere else in the module.
        $others = array_values(array_diff($statuses, [GiftCardPaymentService::STATUS_SPENT]));

        if ([] === $others) {
            return $query->filterBySpent();
        }

        return $query
            ->condition('spent', GiftCardOrderTableMap::COL_STATUS.' = ?', GiftCardPaymentService::STATUS_SPENT)
            ->condition('unset', GiftCardOrderTableMap::COL_STATUS.' IS NULL')
            ->condition('others', GiftCardOrderTableMap::COL_STATUS.' IN ?', $others)
            ->where(['spent', 'unset', 'others'], Criteria::LOGICAL_OR);
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        /** @var GiftCardOrder $giftOrderCard */
        foreach ($loopResult->getResultDataCollection() as $giftOrderCard) {
            $loopResultRow = (new LoopResultRow($giftOrderCard))
                ->set('ID', $giftOrderCard->getId())
                ->set('GIFTCARD_ID', $giftOrderCard->getGiftCardId())
                ->set('SPEND_AMOUNT', $giftOrderCard->getSpendAmount())
                ->set('INITIAL_POSTAGE', $giftOrderCard->getInitialPostage())
                ->set('STATUS', $giftOrderCard->getStatus() ?? GiftCardPaymentService::STATUS_SPENT);

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}
