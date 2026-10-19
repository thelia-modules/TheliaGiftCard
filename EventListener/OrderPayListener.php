<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItemQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Service\GiftCardGenerateService;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class OrderPayListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack            $request,
        protected GiftCardService         $giftCardService,
        protected GiftCardGenerateService $giftCardGenerateService,
        protected EventDispatcherInterface $dispatcher,
    )
    {}

    public function creatCodeGiftCard(OrderEvent $event): void
    {
        if ($event->getOrder()->getStatusId() == TheliaGiftCard::getGiftCardOrderStatusId()) {
            $this->giftCardGenerateService->generateGifcard($event->getOrder());
        }
    }

    /**
     * Links the recipient details typed for each gift card of the cart to the order line of
     * that card; the card generated when the order is paid is then linked to them.
     *
     * The cart is the one the order names, never the session cart: once an order is paid the
     * session hands back a new cart not saved yet, whose id is null, and filtering on a null
     * cart id reads the rows that belong to no cart (the details of the cards created from
     * the back office).
     *
     * @throws PropelException
     */
    public function onOrderPayGiftCardHandleInfo(OrderEvent $event): void
    {
        if (!$event->hasPlacedOrder()) {
            return;
        }

        $order = $event->getPlacedOrder();
        $cartId = $order->getCartId();

        if (null === $order->getId() || null === $cartId) {
            return;
        }

        $cartNewGiftCards = GiftCardInfoCartQuery::create()
            ->filterByCartId($cartId)
            ->filterByCartItemId(null, Criteria::ISNOTNULL)
            ->find();

        $exclude = [];

        /** @var GiftCardInfoCart $cartGiftCard */
        foreach ($cartNewGiftCards as $cartGiftCard) {
            $cartProduct = CartItemQuery::create()->findPk($cartGiftCard->getCartItemId());

            if (null === $cartProduct) {
                continue;
            }

            foreach ($order->getOrderProducts() as $orderProduct) {
                if ($cartProduct->getProductSaleElementsId() == $orderProduct->getProductSaleElementsId() &&
                    !in_array($orderProduct->getId(), $exclude) && !in_array($cartGiftCard->getId(), $exclude)) {
                    $cartGiftCard
                        ->setOrderProductId($orderProduct->getId())
                        ->save();

                    $exclude[] = $orderProduct->getId();
                    $exclude[] = $cartGiftCard->getId();
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['creatCodeGiftCard', 128],
            TheliaEvents::ORDER_PAY => ['onOrderPayGiftCardHandleInfo', 100],
        ];
    }
}