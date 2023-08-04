<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Loop;

use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;

class GiftCardOrderList extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntListTypeArgument('order_id')
        );
    }

    public function buildModelCriteria()
    {
        return GiftCardOrderQuery::create()
        ->filterByOrderId($this->getOrderId());
    }

    public function parseResults(LoopResult $loopResult)
    {
        /** @var GiftCardOrder $giftOrderCard */
        foreach ($loopResult->getResultDataCollection() as $giftOrderCard) {
            $loopResultRow = (new LoopResultRow($giftOrderCard))
                ->set('ID', $giftOrderCard->getId())
                ->set('GIFTCARD_ID', $giftOrderCard->getGiftCardId())
                ->set('SPEND_AMOUNT', $giftOrderCard->getSpendAmount())
                ->set('INITIAL_POSTAGE', $giftOrderCard->getInitialPostage())
                ->set('STATUS', $giftOrderCard->getStatus());

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}