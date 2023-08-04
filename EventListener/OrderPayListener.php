<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use DateTime;
use Exception;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItemQuery;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardGenerateService;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class OrderPayListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack            $request,
        protected GiftCardService         $giftCardService,
        protected GiftCardGenerateService $giftCardGenerateService
    )
    {}

    public function creatCodeGiftCard(OrderEvent $event): void
    {
        if ($event->getOrder()->getStatusId() == TheliaGiftCard::getGiftCardOrderStatusId()) {
            $this->giftCardGenerateService->generateGifcard($event->getOrder());
        }
    }

    /**
     * @throws PropelException
     * @throws Exception
     */
    public function onOrderPayGiftCard(OrderEvent $event): void
    {
        $order = $event->getPlacedOrder();
        $cart = $this->request->getSession()->getSessionCart();
        $cartId = $cart->getId();

        $cartGiftCards = GiftCardCartQuery::create()
            ->filterByCartId($cartId)
            ->find();

        /** @var GiftCardCart $cartGiftCard */
        foreach ($cartGiftCards as $cartGiftCard) {
            $orderGiftCard = GiftCardOrderQuery::create()
                ->filterByOrderId($order->getId())
                ->filterByGiftCardId($cartGiftCard->getId())
                ->findOne();

            $orderGiftCard?->delete();

            $giftCard = $cartGiftCard->getGiftCard();

            // test date validité
            $dateNow = new DateTime();
            $delta = null;
            
            if ($giftCard->getExpirationDate()) {
                $delta = $dateNow->diff($giftCard->getExpirationDate())->format('%r');
            }

            if (null != $delta) {
                return;
            }

            // Test capacité
            if ($giftCard->getAmount() < ($giftCard->getSpendAmount() + $cartGiftCard->getSpendAmount())) {
                return;
            }

            $newOrderGiftCard = new GiftCardOrder();
            $newOrderGiftCard
                ->setGiftCardId($giftCard->getId())
                ->setOrderId($order->getId())
                ->setSpendAmount($cartGiftCard->getSpendAmount())
                ->setInitialPostage($order->getPostage())
                ->save();

            $currentSpendAmount = $giftCard->getSpendAmount();

            $giftCard
                ->setSpendAmount($currentSpendAmount + $cartGiftCard->getSpendAmount())
                ->save();
        }
    }

    /**
     * @throws PropelException
     */
    public function onOrderPayGiftCardHandleInfo(OrderEvent $event): void
    {
        //Quand un carte cadeau est achetée, on attribue les id order sur la table info,
        // quand la carte sera activé, on attribue id carte cadeau

        $order = $event->getPlacedOrder();

        $cartId = $this->request->getSession()->getSessionCart()->getId();

        $cartNewGiftCards = GiftCardInfoCartQuery::create()
            ->filterByCartId($cartId)
            ->find();

        $exclude = [];

        /** @var GiftCardInfoCart $cartGiftCard */
        foreach ($cartNewGiftCards as $cartGiftCard) {
            if ($cartGiftCard) {
                $cartProduct = CartItemQuery::create()->findPk($cartGiftCard->getCartItemId());

                foreach ($order->getOrderProducts() as $orderProduct) {
                    $orderProductCurrent = $orderProduct->getProductSaleElementsId();

                    if ($cartProduct->getProductSaleElementsId() == $orderProductCurrent &&
                        !in_array($orderProduct->getId(), $exclude) && !in_array($cartGiftCard->getId(), $exclude)) {

                        $cartNewCustom = GiftCardInfoCartQuery::create()
                            ->filterByCartId($cartId)
                            ->filterByCartItemId($cartGiftCard->getCartItemId())
                            ->findOne();

                        $cartNewCustom
                            ->setOrderProductId($orderProduct->getId())
                            ->save();

                        $exclude[] = $orderProduct->getId();
                        $exclude[] = $cartGiftCard->getId();
                    }
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['creatCodeGiftCard', 128],
            TheliaEvents::ORDER_PAY => [
                ['onOrderPayGiftCard', 128],
                ['onOrderPayGiftCardHandleInfo', 100]
            ]
        ];
    }
}