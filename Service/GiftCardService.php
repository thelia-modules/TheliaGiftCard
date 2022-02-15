<?php
/**
 * Created by PhpStorm.
 * User: zawaze
 * Date: 26/11/18
 * Time: 00:35
 */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\AddressQuery;
use Thelia\Model\Base\ProductSaleElementsQuery;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\Map\CartItemTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Module\DeliveryModuleInterface;
use TheliaGiftCard\Model\Base\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardInfoCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardService
{
    /**
     * @var Request
     */
    protected $request;
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(RequestStack $requestStack, EventDispatcherInterface $dispatcher, ContainerInterface $container)
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->dispatcher = $dispatcher;
        $this->container = $container;
    }

    public function getInfoGiftCard($code)
    {
        $query = GiftCardInfoCartQuery::create();

        $giftCardJoin = new Join();
        $giftCardJoin->addExplicitCondition(
            GiftCardInfoCartTableMap::TABLE_NAME,
            'gift_card_id',
            '',
            GiftCardTableMap::TABLE_NAME,
            'ID'
        );
        $giftCardJoin->setJoinType(Criteria::RIGHT_JOIN);

        $query->addJoinObject($giftCardJoin, 'test-code-join');
        $query->where(GiftCardTableMap::CODE . ' = ?', $code, \PDO::PARAM_STR);

        $query
            ->withColumn(GiftCardTableMap::CODE,
                'code'
            );

        $query
            ->withColumn(GiftCardTableMap::AMOUNT,
                'amount'
            );

        $infosCard = $query->findOne();

        if (null !== $infosCard) {
            return [
                'message' => $infosCard->getBeneficiaryMessage(),
                'code' => $infosCard->getVirtualColumn('code'),
                'sponsorName' => $infosCard->getSponsorName(),
                'beneficiaryName' => $infosCard->getBeneficiaryName(),
                'amount' => $infosCard->getVirtualColumn('amount'),
            ];
        }

        return false;
    }

    /**
     * @param $code
     * @param $amount
     * @param $cart
     * @param $order
     * @param $customer
     * @throws \Exception
     */
    public function spendGiftCard($code, $amount, $cart, $order, $customer, $deliveryModuleId = null)
    {
        /** @var GiftCard $giftCard */
        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByStatus(1)
            ->where(GiftCardTableMap::SPEND_AMOUNT . ' < ' . GiftCardTableMap::AMOUNT)
            ->findOne();

        if (null == $giftCard) {
            throw new FormValidationException('Gift Card invalid');
        }

        $country = $customer->getDefaultAddress()->getCountry();

        $this->deleteGiftCardOnCart($giftCard);
        $this->handlePostage($cart, $order, $country, $deliveryModuleId);

        $this->handleSpendAmount($amount, $giftCard, $cart, $order, $country);

        if (round($amount, 4) == 0) {
            return;
        }

        $cartItem = $this->setGiftCardOnCart($amount, $cart);
        return $this->saveGiftCardCart($amount, $giftCard, $cart, $cartItem);
    }

    public function getProductCartItem()
    {
        $product = ProductQuery::create()
            ->filterByRef(TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF)
            ->findOne();
        try {
            /** @var ProductSaleElements $pse */
            $pse = $product->getProductSaleElementss()->getFirst();

            if (null == $pse) {
                throw new \Exception('Gift Card INVALID');
            }
        } catch (PropelException $e) {
            //TODO TLOG ICI
            return $e->getMessage();
        }

        return [$product, $pse];
    }

    public function handleSpendAmount(&$amount, GiftCard $giftCard, Cart $cart, Order $order, Country $country)
    {
        $amount = ($amount > 0) ? $amount * -1 : $amount;

        $amountAvailable = $giftCard->getAmount() - $giftCard->getSpendAmount();
        $amountAvailable = ($amountAvailable < 0) ? 0 : $amountAvailable;
        $amountAvailable = $amountAvailable * -1;

        if ($amount < $amountAvailable) {
            $amount = ($amountAvailable > 0) ? $amountAvailable : $amountAvailable;
        }

        $totalCartAmount = $cart->getTaxedAmount($country) + (float)$order->getPostage();
        $totalCartAmount = $totalCartAmount * -1;
        $giftCardCartItem = null;

        // retrieve gift cart item
        foreach ($cart->getCartItems() as $cartItem) {
            if ($cartItem->getProduct()->getRef() === "GIFTCARD_CART") {
                $giftCardCartItem = $cartItem;
            }
        }

        // If amount is superior than totalCartAmount
        if ($amount < $totalCartAmount) {
            // if no gift cards is set in the cart
            if (null == $giftCardCartItem) {
                $amount = $totalCartAmount;
            } else {
                $totalCartWithoutGiftCards = 0;

                // get the totat of the cart without GIFTCARD_CART
                foreach ($cart->getCartItems() as $cartItem) {

                    // If cartItem is not a gift card
                    if ($cartItem->getProduct()->getRef() !== "GIFTCARD_CART") {

                        // check if its promo
                        if ($cartItem->getPromo() === 1) {
                            $totalCartWithoutGiftCards = $totalCartWithoutGiftCards + $cartItem->getTaxedPromoPrice($country) * -1;
                        } else {
                            $totalCartWithoutGiftCards = $totalCartWithoutGiftCards + $cartItem->getTaxedPrice($country) * -1;
                        }
                    }
                }

                // if amount is superior than total cart without gift cards
                if ($amount < $totalCartWithoutGiftCards) {
                    $amount = $totalCartWithoutGiftCards;
                }

            }
        }

        $delta = $cart->getTaxedAmount($country) + $amount;

        if ($delta < 0) {
            if (($postage = bcadd((float)$order->getPostage(), $delta, 6)) < 0) {
                $postage = 0;
            }

            $order->setPostage($postage);
        }
    }

    public function handlePostage(Cart $cart, Order $order, $country, $deliveryModuleId = null)
    {
        if ($order->getDeliveryModuleId() && !$deliveryModuleId) {
            $deliveryModuleId = $order->getDeliveryModuleId();
        }

        if (!$deliveryModuleId) {
            $order->setPostage(0);
            return 0;
        }

        $deliveryModule = ModuleQuery::create()->findPk($deliveryModuleId);

        /** @var DeliveryModuleInterface $moduleInstance */
        $moduleInstance = $deliveryModule->getDeliveryModuleInstance($this->container);

        $address = $this->getDeliveryAddress();

        $deliveryPostageEvent = new DeliveryPostageEvent($moduleInstance, $cart, $address, $country, null);

        $this->dispatcher->dispatch(
            $deliveryPostageEvent,
            TheliaEvents::MODULE_DELIVERY_GET_POSTAGE
        );

        $postage = $deliveryPostageEvent->getPostage();

        if (!$postage) {
            return 0;
        }

        $order->setPostage($postage->getAmount());

        return $postage->getAmount();
    }

    public function deleteGiftCardOnCart(GiftCard $giftCard)
    {
        $giftCardOnCart = GiftCardCartQuery::create()
            ->filterByGiftCardId($giftCard->getId())
            ->findOne();

        if (null != $giftCardOnCart) {
            $d = $giftCardOnCart->getCartItem();
            if ($d) {
                $d->delete();
            }
        }

        list($product, $pse) = $this->getProductCartItem();

        //Delete les cartes orphelines
        $this->deleteOrphelin($product);
    }

    public function deleteOrphelin($product)
    {
        $missingGiftCardsOnCart = CartItemQuery::create()
            ->filterByProductId($product->getId());

        $giftCardCartJoin = new Join();
        $giftCardCartJoin->addExplicitCondition(
            CartItemTableMap::TABLE_NAME,
            'ID',
            '',
            GiftCardCartTableMap::TABLE_NAME,
            'CART_ITEM_ID'
        );
        $giftCardCartJoin->setJoinType(Criteria::LEFT_JOIN);

        $missingGiftCardsOnCart->addJoinObject($giftCardCartJoin, 'test-code-join');
        $missingGiftCardsOnCart->where(GiftCardCartTableMap::ID . ' IS NULL');

        $missingGiftCardsOnCart->find();

        foreach ($missingGiftCardsOnCart as $missingGiftCardOnCart) {
            $missingGiftCardOnCart->delete();
        }
    }

    public function saveGiftCardCart($amount, GiftCard $giftCard, Cart $cart, CartItem $cartItem)
    {
        $newGiftCardOnCart = GiftCardCartQuery::create()
            ->filterByGiftCardId($giftCard->getId())
            ->findOne();

        if (null == $newGiftCardOnCart) {
            $newGiftCardOnCart = new GiftCardCart();
        }

        $newGiftCardOnCart
            ->setGiftCardId($giftCard->getId())
            ->setCartId($cart->getId())
            ->setCartItemId($cartItem->getId())
            ->setSpendAmount($amount * -1)
            ->save();

        return $amount;
    }

    /**
     * @param $amount
     * @param $giftCard
     * @param $cart
     * @return CartItem
     * @throws \Exception
     */
    public function setGiftCardOnCart($amount, $cart)
    {
        list($product, $pse) = $this->getProductCartItem();

        $cartEvent = $this->setCartEvent(1, $product->getId(), $pse->getId(), $cart);

        $this->addGiftCardCartItems($cartEvent, $amount);

        return $cartEvent->getCartItem();
    }

    public function reset()
    {
        try {
            $cartId = $this->request->getSession()->getSessionCart()->getId();

            GiftCardCartQuery::create()
                ->filterByCartId($cartId)
                ->delete();

            list($product, $pse) = $this->getProductCartItem();

            $this->deleteOrphelin($product);
        } catch (\Exception $e) {

        }
    }

    public function getGiftCardCountOnCart()
    {
        $cartId = $this->request->getSession()->getSessionCart()->getId();

        if ($cartId) {
            return GiftCardCartQuery::create()
                ->filterByCartId($cartId)
                ->count();
        }
    }

    /**
     * @param $quantity
     * @param $productId
     * @param $pseId
     * @param $cart
     * @param null $cartItemId
     * @return CartEvent
     */
    protected function setCartEvent($quantity, $productId, $pseId, $cart, $cartItemId = null)
    {
        $cartEvent = new CartEvent($cart);

        $cartEvent->setNewness(true);
        $cartEvent->setAppend(false);
        $cartEvent->setQuantity($quantity);
        $cartEvent->setProductSaleElementsId($pseId);
        $cartEvent->setProduct($productId);
        $cartEvent->setCartItemId($cartItemId);

        return $cartEvent;
    }

    protected function addGiftCardCartItems(CartEvent $event, $price)
    {
        $cart = $event->getCart();
        $newness = $event->getNewness();
        $append = $event->getAppend();
        $quantity = $event->getQuantity();
        $currency = $cart->getCurrency();

        $productSaleElementsId = $event->getProductSaleElementsId();
        $productId = $event->getProduct();

        $findItemEvent = clone $event;

        $this->dispatcher->dispatch($findItemEvent, TheliaEvents::CART_FINDITEM);

        $cartItem = $findItemEvent->getCartItem();

        if ($cartItem === null || $newness) {
            $productSaleElements = ProductSaleElementsQuery::create()->findPk($productSaleElementsId);

            if (null !== $productSaleElements) {
                $cartItem = new CartItem();
                $cartItem->setDisptacher($this->dispatcher);
                $cartItem
                    ->setCart($cart)
                    ->setProductId($productId)
                    ->setProductSaleElementsId($productSaleElements->getId())
                    ->setQuantity($quantity)
                    ->setPrice($price)
                    ->setPromoPrice($price)
                    ->setPromo(0)
                    ->setPriceEndOfLife(time() + ConfigQuery::read("cart.priceEOF", 60 * 60 * 24 * 30));
                $cartItem->save();

            } else {
                return null;
            }
        } elseif ($append && $cartItem !== null) {
            $cartItem->addQuantity($quantity)->save();
        }

        $event->setCartItem($cartItem);

        return $event;
    }

    protected function getDeliveryAddress()
    {
        $address = null;

        $addressId = $this->request->getSession()->getOrder()->getChoosenDeliveryAddress();

        if (!empty($addressId)) {
            $address = AddressQuery::create()->findPk($addressId);
        }

        return $address;
    }
}