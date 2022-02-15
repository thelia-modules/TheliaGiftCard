<?php

namespace TheliaGiftCard\EventListener;

use OpenApi\Events\ModelExtendDataEvent;
use OpenApi\Model\Api\Cart;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\TheliaGiftCard;

class CartApiListener implements EventSubscriberInterface
{
    public function modifyCart(ModelExtendDataEvent $event)
    {
        /** @var Cart $modelCart */
        $modelCart = $event->getModel();

        $cartTotalGiftCart = $this->getCartTotalGiftCart($modelCart->getId());

        $event->setExtendDataKeyValue("total_amount_gift_card", $cartTotalGiftCart);

        $modelCart->setItems($this->deleteGiftCardCartItem($modelCart->getItems()));
    }

    protected function deleteGiftCardCartItem($cartItems)
    {
        $cartItems = array_filter($cartItems, function ($cartItem){
            $product = $cartItem->getProduct();
            if($product->getReference() === TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF){
                return false;
            }
            return true;
        });

        return $cartItems;
    }

    protected function getCartTotalGiftCart($cartId)
    {
        $total = 0;

        $giftCardsCart = GiftCardCartQuery::create()
            ->filterByCartId($cartId)
            ->find();

        foreach ($giftCardsCart as $giftCardCart) {
            $total += (float)$giftCardCart->getSpendAmount();
        }

        return $total;
    }

    public static function getSubscribedEvents()
    {
        //TODO: test class exist
        return [
            ModelExtendDataEvent::ADD_EXTEND_DATA_PREFIX . "cart" => ['modifyCart', 10],
        ];
    }
}