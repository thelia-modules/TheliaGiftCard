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
use Thelia\Core\Event\Cart\CartItemDuplicationItem;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItem;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;

class CartListener implements EventSubscriberInterface
{
    /**
     * @var Request
     */
    private $request;

    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function duplicateCartGiftCardInfo(CartItemDuplicationItem $cartEvent)
    {
        //En cas de connexion pendant le tunnel de commande, on redistribue les nouveaux id cart item

        /** @var CartItem $oldItem */
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

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::CART_ITEM_DUPLICATE => ['duplicateCartGiftCardInfo', 250]
        ];
    }
}