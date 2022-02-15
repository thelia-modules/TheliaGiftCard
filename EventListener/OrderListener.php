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
use Thelia\Mailer\MailerFactory;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Order;
use TheliaGiftCard\Model\GiftCardEmailStatusQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;

class OrderListener implements EventSubscriberInterface
{
    /**
     * @var MailerFactory
     */
    protected $mailer;

    /**
     * @var Request
     */
    private $request;

    public function __construct(RequestStack $requestStack, MailerFactory $mailer)
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->mailer = $mailer;
    }

    /**
     * @return \Thelia\Mailer\MailerFactory
     */
    public function getMailer()
    {
        return $this->mailer;
    }

    public function onOrderPayGiftCardHandleInfo(OrderEvent $event)
    {
        //Quand un carte cadeau est achetée, on attribue les id order sur la table info,
        // quand la carte sera activé, on attribue id carte cadeau

        /** @var Order $order */
        $order = $event->getPlacedOrder();

        // Envoi de l'email spécifié en backoffice si il y a bien une carte cadeau dans la commande.
        if ($this->orderContainsAGiftCard($order)) {
            if (null !== $giftCardEmailData = GiftCardEmailStatusQuery::create()->findOneBySpecialStatus('ORDER_CREATED')) {
                if (null !== $giftCardEmailData->getEmailText() && null !== $giftCardEmailData->getEmailSubject()) {
                    $this->getMailer()->sendEmailToCustomer(
                        'gift_card_customer_notification',
                        $order->getCustomer(),
                        [
                            'order' => $order,
                            'emailText' => $giftCardEmailData->getEmailText(),
                            'emailSubject' => $giftCardEmailData->getEmailSubject()
                        ]
                    );
                }
            }
        }

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

    public function onOrderStatusUpdate(OrderEvent $event)
    {
        $order = $event->getOrder();
        $newStatus = $event->getStatus();

        if ($this->orderContainsAGiftCard($order)) {
            if (null !== $giftCardEmailData = GiftCardEmailStatusQuery::create()->findOneByStatusId($newStatus)) {
                if (null !== $giftCardEmailData->getEmailText() && null !== $giftCardEmailData->getEmailSubject()) {
                    $this->getMailer()->sendEmailToCustomer(
                        'gift_card_customer_notification',
                        $order->getCustomer(),
                        [
                            'order' => $order,
                            'emailText' => $giftCardEmailData->getEmailText(),
                            'emailSubject' => $giftCardEmailData->getEmailSubject()
                        ]
                    );
                }
            }
        }
    }

    protected function orderContainsAGiftCard(Order $order)
    {
        foreach ($order->getOrderProducts() as $product) {
            $pos = stripos( $product->getProductRef(), 'GIFTCARD');

            if (false !== $pos) {
                return 1;
            }
        }
        return 0;
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderStatusUpdate', 132],
            TheliaEvents::ORDER_PAY => ['onOrderPayGiftCardHandleInfo', 64],
        ];
    }
}