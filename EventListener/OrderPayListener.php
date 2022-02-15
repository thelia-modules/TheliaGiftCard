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
use Thelia\Model\AddressQuery;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class OrderPayListener implements EventSubscriberInterface
{

    /**
     * @var Request
     */
    private $request;

    /**
     * @var GiftCardService
     */
    private $giftCardService;

    public function __construct(RequestStack $request, GiftCardService $giftCardService)
    {
        $this->request = $request;
        $this->giftCardService = $giftCardService;
    }

    public function creatCodeGiftCard(OrderEvent $event)
    {
        if ($event->getOrder()->getStatusId() == TheliaGiftCard::getGiftCardOrderStatusId()) {
            /** @var Order $order */
            $order = $event->getOrder();

            //Comptage du nombre de carte cadeau à créer
            $countMaxbyAmount = $this->getCountGiftCards($order->getOrderProducts());

            /** @var  CartItem $item */
            foreach ($order->getOrderProducts() as $orderProduct) {
                $pse = ProductSaleElementsQuery::create()->findPk($orderProduct->getProductSaleElementsId());
                $productId = $pse->getProduct()->getId();
                $tabProductGiftCard = TheliaGiftCard::getGiftCardProductList();

                //Si l'orderProduct correspond à une carte cadeau
                if (in_array($productId, $tabProductGiftCard)) {
                    $orederId = $order->getId();
                    $price = $orderProduct->getPrice();
                    $TaxAmount = 0;

                    $orderProductTaxes = $orderProduct->getOrderProductTaxes()->getData();

                    foreach ($orderProductTaxes as $orderProductTax) {
                        $TaxAmount = $orderProductTax->getAmount();
                    }

                    for ($i = 1; $i <= $orderProduct->getQuantity(); $i++) {
                        $expirationDate = new \DateTime("+12 months");
                        $giftCards = GiftCardQuery::create()
                            ->filterByOrderId($order->getId())
                            ->filterByProductId($productId)
                            ->find();

                        if ($giftCards->count() < $countMaxbyAmount[$productId]) {
                            // Création de carte cadeaux
                            $amount = (float)$price + (float)$TaxAmount;

                            // Forcer montant de la carte cadeau si besoin (montant de la feature)
                            $featureAmount = FeatureProductQuery::create()
                                ->filterByProductId($productId)
                                ->filterByFreeTextValue(1)
                                ->findOne();

                            if (null !== $featureAmount) {
                                $amount = (float)$featureAmount->getFeatureAv()->setLocale('fr_FR')->getTitle();
                            }

                            $newGiftCard = new GiftCard();
                            $newGiftCard
                                ->setProductId($productId)
                                ->setSponsorCustomerId($order->getCustomer()->getId())
                                ->setOrderId($orederId)
                                ->setCode(TheliaGiftCard::GENERATE_CODE())
                                ->setAmount($amount)
                                ->setSpendAmount(0)
                                ->setExpirationDate($expirationDate)
                                ->setStatus(0)
                                ->save();
                        }

                        if (null != $newGiftCard) {
                            $giftCardInfo = GiftCardInfoCartQuery::create()
                                ->filterByOrderProductId($orderProduct->getId())
                                ->findOne();

                            if (null != $giftCardInfo) {
                                $giftCardInfo
                                    ->setGiftCardId($newGiftCard->getId())
                                    ->save();
                            }
                        }
                    }
                }
            }
        }
    }

    protected function getCountGiftCards($orderProducts)
    {
        $cpt = [];

        /** @var OrderProduct $orderProduct */
        foreach ($orderProducts as $orderProduct) {
            $pse = ProductSaleElementsQuery::create()->findPk($orderProduct->getProductSaleElementsId());
            $productId = $pse->getProduct()->getId();

            //retourne la liste des id produits correspondant à une carte cadeau
            $tabProductsGiftCard = TheliaGiftCard::getGiftCardProductList();

            // Determiner le nombre de produit correspondant à une carte cadeau dans la commande initiale
            if (in_array($productId, $tabProductsGiftCard)) {
                if (isset($cpt[$productId])) {
                    $cpt[$productId] += $orderProduct->getQuantity();
                } else {
                    $cpt[$productId] = $orderProduct->getQuantity();
                }
            }
        }

        return $cpt;
    }

    public function onOrderPayGiftCard(OrderEvent $event)
    {
        /** @var Order $order */
        $order = $event->getPlacedOrder();
        $cart = $this->request->getSession()->getSessionCart();
        $cartId = $cart->getId();

        //delete cart items orpheline
        list($product, $pse) = $this->giftCardService->getProductCartItem();
        $this->giftCardService->deleteOrphelin($product);

        $cartGiftCards = GiftCardCartQuery::create()
            ->filterByCartId($cartId)
            ->find();

        $exclude = [];

        /** @var GiftCardCart $cartGiftCard */
        foreach ($cartGiftCards as $cartGiftCard) {
            $orderGiftCard = GiftCardOrderQuery::create()
                ->filterByOrderId($order->getId())
                ->filterByGiftCardId($cartGiftCard->getId())
                ->findOne();

            if (null !== $orderGiftCard) {
                $orderGiftCard->delete();
            }

            $giftCard = GiftCardQuery::create()
                ->filterById($cartGiftCard->getGiftCardId())
                ->filterByStatus(1)
                ->findOne();

            if (null == $giftCard) {
                $this->deletedCartItem($cartGiftCard->getCartItemId());
                return;
            }

            // test date validité
            $dateNow = new \DateTime();
            $delta = $dateNow->diff($giftCard->getExpirationDate())->format('%r');

            if (null != $delta) {
                $this->deletedCartItem($cartGiftCard->getCartItemId());
                return;
            }

            // Test capacité
            if ($giftCard->getAmount() < ($giftCard->getSpendAmount() + $cartGiftCard->getSpendAmount())) {
                $this->deletedCartItem($cartGiftCard->getCartItemId());
                return;
            }

            $newOrderGiftCard = new GiftCardOrder();
            $newOrderGiftCard
                ->setGiftCardId($giftCard->getId())
                ->setOrderId($order->getId())
                ->setSpendAmount($cartGiftCard->getSpendAmount())
                ->setInitialPostage($this->request->getSession()->get(TheliaGiftCard::GIFT_CARD_SESSION_POSTAGE))
                ->save();

            $currentSpendAmount = $giftCard->getSpendAmount();

            $giftCard
                ->setSpendAmount($currentSpendAmount + $cartGiftCard->getSpendAmount())
                ->save();
        }
    }

    protected function deletedCartItem($cartItemId)
    {
        $cartItem = CartItemQuery::create()->findPk($cartItemId);

        if (null !== $cartItem) {
            $cartItem->delete();
        }
    }

    protected function checkCartOnGiftCardOrder(Cart $cart, Order $order)
    {
        $orderCart= OrderQuery::create()->filterByCartId($cart->getId());
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['creatCodeGiftCard', 128],
            TheliaEvents::ORDER_PAY => ['onOrderPayGiftCard', 128]
        ];
    }
}