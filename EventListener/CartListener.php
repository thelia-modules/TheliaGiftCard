<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartItemDuplicationItem;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItem;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Service\GiftCardService;

class CartListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack,
        protected GiftCardService $giftCardService
    )
    {
    }

    /**
     * @throws PropelException
     */
    public function duplicateCartGiftCardInfo(CartItemDuplicationItem $cartEvent): void
    {
        //En cas de connexion pendant le tunnel de commande, on redistribue les nouveaux id cart item

        $oldItem = $cartEvent->getOldItem();
        $oldCartId = $oldItem->getCartId();

        /** @var CartItem $oldItem */
        $newItem = $cartEvent->getNewItem();
        $newCartId = $newItem->getCartId();

        $oldItem = $oldItem->getId();
        $newItem = $newItem->getId();

        $cartInfoGC = GiftCardInfoCartQuery::create()
            ->filterByCartId($oldCartId)
            ->filterByCartItemId($oldItem)
            ->findOne();

        if ($cartInfoGC) {
            $cartInfoGC->setCartId($newCartId);
            $cartInfoGC->setCartItemId($newItem);
            $cartInfoGC->save();
        }
    }

    public function resetGiftCard(): void
    {
        $this->giftCardService->reset();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::CART_ITEM_DUPLICATE => ['duplicateCartGiftCardInfo', 250],
            TheliaEvents::CART_DELETEITEM => ['resetGiftCard', 100],
            TheliaEvents::CART_ADDITEM => ['resetGiftCard', 100]
        ];
    }
}