<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

class OrderAfterPayListener implements EventSubscriberInterface
{
    public function __construct(protected RequestStack $requestStack)
    {
    }

    /**
     * @throws PropelException
     */
    public function onOrderAfterPayGiftCard(OrderEvent $event): void
    {
        // on reset le postage prévu et on delete les orders produits de carte cadeaux

        $order = $event->getPlacedOrder();
        $orderGiftCards = GiftCardOrderQuery::create()
            ->filterByOrderId($order->getId())
            ->find();

        if (0 === \count($orderGiftCards)) {
            return;
        }

        $postage = '0';

        /** @var GiftCardOrder $orderGiftCard */
        foreach ($orderGiftCards as $orderGiftCard) {
            $postage = (string) $orderGiftCard->getInitialPostage();
        }

        $orderProducts = $order->getOrderProducts();

        /** @var OrderProduct $orderProduct */
        foreach ($orderProducts as $orderProduct) {
            if (TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF === $orderProduct->getProductRef()) {
                $orderProduct->delete();
                if ($orderProduct->getCartItemId()) {
                    CartItemQuery::create()
                        ->filterById($orderProduct->getCartItemId())
                        ->delete();
                }
            }
        }

        $order
            ->setPostage($postage)
            ->save();
    }

    /**
     * A cancelled or refunded order gives back what it spent from gift cards, and takes back the
     * cards it bought as long as nothing was spent from them: a card already used stays as it is, the
     * shop settles the rest with the customer.
     *
     * @throws PropelException
     */
    public function onOrderCancelGiftCard(OrderEvent $event): void
    {
        $order = $event->getOrder();

        // A refunded order is taken back like a cancelled one: the buyer got the money back.
        if (!$order->isCancelled() && !$order->isRefunded()) {
            return;
        }

        $this->creditSpentAmounts($order);
        $this->disableUnspentPurchasedCards($order);
    }

    /**
     * @throws PropelException
     */
    private function creditSpentAmounts(Order $order): void
    {
        $giftCardsOrder = GiftCardOrderQuery::create()
            ->filterByOrderId($order->getId())
            ->find();

        /** @var GiftCardOrder $giftCardOrder */
        foreach ($giftCardsOrder as $giftCardOrder) {
            $currentGiftCard = $giftCardOrder->getGiftCard();

            $currentGiftCard
                ->setSpendAmount(bcsub((string) ($currentGiftCard->getSpendAmount() ?? '0'), (string) ($giftCardOrder->getSpendAmount() ?? '0'), 6))
                ->save();

            $giftCardOrder->delete();
        }
    }

    private function disableUnspentPurchasedCards(Order $order): void
    {
        GiftCardQuery::create()
            ->filterByOrderId($order->getId())
            ->filterByStatus(1)
            ->where('COALESCE('.GiftCardTableMap::COL_SPEND_AMOUNT.', 0) <= 0')
            ->update(['Status' => 0]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderCancelGiftCard', 1],
        ];
    }
}
