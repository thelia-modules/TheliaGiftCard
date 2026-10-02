<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItemQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Exception\GiftCardPaymentRefusedException;
use TheliaGiftCard\Service\GiftCardGenerateService;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class OrderPayListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack            $request,
        protected GiftCardService         $giftCardService,
        protected GiftCardGenerateService $giftCardGenerateService,
        protected EventDispatcherInterface $dispatcher,
        protected GiftCardPaymentService $giftCardPaymentService,
    )
    {}

    public function creatCodeGiftCard(OrderEvent $event): void
    {
        if ($event->getOrder()->getStatusId() == TheliaGiftCard::getGiftCardOrderStatusId()) {
            $this->giftCardGenerateService->generateGifcard($event->getOrder());
        }
    }

    /**
     * Debits the cards put on the cart the order comes from, once the order exists and before
     * the payment module is called: the module reads the total less what was debited
     * (PaymentListener::handleGiftCard), and a card refused here leaves an unpaid order rather
     * than an order a payment module already marked paid.
     *
     * @throws GiftCardPaymentRefusedException when a card of the cart can no longer pay its share
     */
    public function onOrderPayGiftCard(OrderEvent $event): void
    {
        $order = $event->hasPlacedOrder() ? $event->getPlacedOrder() : $event->getOrder();

        if (null === $order->getId() || null === $order->getCartId()) {
            return;
        }

        $this->giftCardPaymentService->debitCart($order, (int) $order->getCartId());
    }

    /**
     * @throws PropelException
     */
    public function onOrderPayGiftCardHandleInfo(OrderEvent $event): void
    {
        //Quand un carte cadeau est achetée, on attribue les id order sur la table info,
        // quand la carte sera activé, on attribue id carte cadeau

        $order = $event->getPlacedOrder();

        $request = $this->request->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }
        $cartId = $request->getSession()->getSessionCart($this->dispatcher)->getId();

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
            // Before the confirmation e-mails (128) and before the payment module is called.
            TheliaEvents::ORDER_BEFORE_PAYMENT => ['onOrderPayGiftCard', 192],
            TheliaEvents::ORDER_PAY => ['onOrderPayGiftCardHandleInfo', 100],
        ];
    }
}