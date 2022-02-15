<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Smarty\Plugins;

use Thelia\Model\Base\AddressQuery;
use Thelia\Model\ProductQuery;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;
use TheliaSmarty\Template\AbstractSmartyPlugin;
use TheliaSmarty\Template\SmartyPluginDescriptor;
use Thelia\Core\HttpFoundation\Request;

class GiftCardSmartyPlugin extends AbstractSmartyPlugin
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var GiftCardService
     */
    private $giftCardService;

    public function __construct(Request $request, GiftCardService $giftCardService)
    {
        $this->request = $request;
        $this->giftCardService = $giftCardService;
    }

    public function getPluginDescriptors()
    {
        return array(
            new SmartyPluginDescriptor('function', 'getGitCardInfo', $this, 'getGitCardInfo'),
            new SmartyPluginDescriptor('function', 'isGiftCardProduct', $this, 'isGiftCardProduct'),
            new SmartyPluginDescriptor('function', 'wasGiftCardInCart', $this, 'wasGiftCardInCart'),
            new SmartyPluginDescriptor('function', 'getGiftCardCartAmount', $this, 'getGiftCardCartAmount'),
            new SmartyPluginDescriptor('function', 'getGiftCardCategoryId', $this, 'getGiftCardCategoryId'),
            new SmartyPluginDescriptor('function', 'resetGiftCardOncart', $this, 'resetGiftCardOncart'),
            new SmartyPluginDescriptor('function', 'getOrderSessionPostage', $this, 'getOrderSessionPostage'),
            new SmartyPluginDescriptor('function', 'getCartTotalHTWhitoutGiftCart', $this, 'getCartTotalHTWhitoutGiftCart'),

        );
    }

    public function getCartTotalHTWhitoutGiftCart($params, $smarty)
    {
        $cart = $this->request->getSession()->getSessionCart();
        $total = 0;

        if (null != $cart) {
            foreach ($cart->getCartItems() as $cartItem) {
                $product = $cartItem->getProduct();
                if($product->getRef() === TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF){
                    continue;
                }

                if ($cartItem->getPromo()) {
                    $total += $cartItem->getPromoPrice() * $cartItem->getQuantity();
                } else{
                    $total += $cartItem->getPrice()  * $cartItem->getQuantity();
                }
            }
        }

        return $total;

    }

    public function getGitCardInfo($params, $smarty)
    {
        //Récupération des informations liées à l'achat d'une carte cadeau (bénéficiare, message ...)

        $cartItemId = $params['cart_item_id'];
        $code = $params['code'];

        $smarty->assign(['sponsor_name' => ""]);
        $smarty->assign(['beneficiary_name' => ""]);
        $smarty->assign(['beneficiary_message' => ""]);

        if ($cartItemId) {
            $cart = $this->request->getSession()->getSessionCart();

            $infoGiftCard = GiftCardInfoCartQuery::create()
                ->filterByCartId($cart->getId())
                ->filterByCartItemId($cartItemId)
                ->findOne();
        }

        if ($code) {
            $giftCard = GiftCardQuery::create()->findOneByCode($code);

            if (null == $giftCard) {
                return;
            }

            $infoGiftCard = GiftCardInfoCartQuery::create()->findOneByGiftCardId($giftCard->getId());
        }

        if ($infoGiftCard) {
            $smarty->assign(['sponsor_name' => $infoGiftCard->getSponsorName()]);
            $smarty->assign(['beneficiary_name' => $infoGiftCard->getBeneficiaryName()]);
            $smarty->assign(['beneficiary_message' => $infoGiftCard->getBeneficiaryMessage()]);
        }
    }

    public function isGiftCardProduct($params)
    {
        $productId = $params['product_id'];
        $tabProductGiftCard = TheliaGiftCard::getGiftCardProductList();

        if (in_array($productId, $tabProductGiftCard)) {
            return true;
        }

        return false;
    }

    public function wasGiftCardInCart($params, $smarty)
    {
        $cart = $this->request->getSession()->getSessionCart();
        if (null != $cart) {
            foreach ($cart->getCartItems() as $cartItem) {
                $currentProduct = $cartItem->getProduct();
                $tabProductGiftCards = TheliaGiftCard::getGiftCardProductList();
                if (in_array($currentProduct->getId(), $tabProductGiftCards)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getGiftCardCartAmount($params, $smarty)
    {
        $cart = $this->request->getSession()->getSessionCart();
        $total = 0;

        $giftCardsCart = GiftCardCartQuery::create()
            ->filterByCartId($cart->getId())
            ->find();

        foreach ($giftCardsCart as $giftCardCart) {
            $total += $giftCardCart->getSpendAmount();
        }

        $smarty->assign(['totalGiftCard' => $total]);

        return $total;
    }

    public function resetGiftCardOncart($params, $smarty)
    {
        if ($this->request->hasSession()) {
            $this->giftCardService->reset();
        }
    }

    public function getOrderSessionPostage($params, $smarty)
    {
        if ($this->request->hasSession()) {
            $postage = $this->request->getSession()->get(TheliaGiftCard::GIFT_CARD_SESSION_POSTAGE);
            $smarty->assign(['realPostageGiftCard' => $postage]);
        }
    }

    public function getGiftCardCategoryId()
    {
        return TheliaGiftCard::getGiftCardCategoryId();
    }
}