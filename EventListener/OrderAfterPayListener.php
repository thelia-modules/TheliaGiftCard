<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\TheliaGiftCard;

class OrderAfterPayListener implements EventSubscriberInterface
{

    /**
     * @var Request
     */
    private $request;

    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function onOrderAfterPayGiftCard(OrderEvent $event)
    {
        //on reset le postage prévu et on delete les orders produits de carte cadeaux

        $order = $event->getPlacedOrder();
        $orderGiftCards = GiftCardOrderQuery::create()
            ->filterByOrderId($order->getId())
            ->find();

        if (count($orderGiftCards) === 0) {
            return null;
        }

        $postage = 0;

        /** @var GiftCardOrder $orderGiftCard */
        foreach ($orderGiftCards as $orderGiftCard) {
            $postage = $orderGiftCard->getInitialPostage();
        }

        $orderProducts = $order->getOrderProducts();

        /** @var OrderProduct $orderProduct */
        foreach ($orderProducts as $orderProduct) {
            if ($orderProduct->getProductRef() == TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF) {
                $orderProduct->delete();
                if($orderProduct->getCartItemId()){
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

    public function onOrderCancelGiftCard(OrderEvent $event)
    {
        // Delete le montant dépensé par une carte cadeau sur une annulation d'order
        if ($event->getOrder()->getOrderStatus()->getCode() == 'canceled') {
            /** @var Order $order */
            $order = $event->getOrder();

            $giftCardsOrder = GiftCardOrderQuery::create()
                ->filterByOrderId($order->getId())
                ->find();

            /** @var GiftCardOrder $giftCardOrder */
            foreach ($giftCardsOrder as $giftCardOrder) {
                $currentGiftCard = $giftCardOrder->getGiftCard();

                $currentSpendAmount = $giftCardOrder->getSpendAmount();

                $currentGiftCard
                    ->setSpendAmount($currentGiftCard->getSpendAmount() - $currentSpendAmount )
                    ->save();

                $giftCardOrder->delete();
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_PAY => ['onOrderAfterPayGiftCard', 1],
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderCancelGiftCard', 1]
        ];
    }
}