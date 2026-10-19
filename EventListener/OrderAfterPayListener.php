<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Model\OrderProduct;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\TheliaGiftCard;

class OrderAfterPayListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack,
        protected GiftCardPaymentService $giftCardPaymentService,
    ) {
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
            ->filterBySpent()
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
     * A refunded order gives back what it spent from gift cards, and a cancelled or refunded order takes
     * back the cards it bought as long as nothing was spent from them: a card already used stays as it
     * is, the shop settles the rest with the customer.
     *
     * What a cancelled order spent is the core's to give back when the order has payment parts
     * (releasePart(), which OrderPaymentPartReleaseListener runs before this listener, at priority 100):
     * this listener does not touch such an order, so that a release the module refused is not credited
     * around the part, which the core then keeps settled. It credits the refunded orders, which the core
     * leaves alone, and the orders placed before the module was a payment part, whatever their status.
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

        if ($order->isRefunded() || !$this->hasPaymentParts($order)) {
            $this->creditSpentAmounts($order);
        }

        $this->disableUnspentPurchasedCards($order);
    }

    private function hasPaymentParts(Order $order): bool
    {
        return null !== $order->getId() && OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->exists();
    }

    /**
     * The core releases the part of an order paid through the module when it cancels the order
     * (releasePart(), once per settled part). This listener gives back the rest: the orders paid
     * before the module was a payment part (no part, only the rows of gift_card_order) and the
     * refunded orders, which the core leaves alone. What the release already gave back is not
     * there to give back again.
     *
     * Called inside a transaction of the core or of the admin API, it writes in it and never ends it:
     * a failure is thrown and whoever opened the transaction rolls it back (a rollBack here would
     * mark the transaction of the caller as lost, and what follows would commit on its own). With no
     * transaction open it opens one, so that the locks of the cards are held until the credit is
     * written.
     */
    private function creditSpentAmounts(Order $order): void
    {
        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);

        if ($connection->inTransaction()) {
            $this->giftCardPaymentService->release((int) $order->getId());

            return;
        }

        $connection->beginTransaction();

        try {
            $this->giftCardPaymentService->release((int) $order->getId());
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
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
