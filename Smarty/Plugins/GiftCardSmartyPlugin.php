<?php

namespace TheliaGiftCard\Smarty\Plugins;

use Symfony\Component\HttpFoundation\RequestStack;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;
use TheliaSmarty\Template\AbstractSmartyPlugin;
use TheliaSmarty\Template\SmartyPluginDescriptor;

/**
 * A DELETE, utiliser uniquement la partie API ou loop
 */

class GiftCardSmartyPlugin extends AbstractSmartyPlugin
{
    /**
     * @var RequestStack
     */
    private RequestStack $requestStack;
    /**
     * @var GiftCardService
     */
    private GiftCardService $giftCardService;

    public function __construct(RequestStack $requestStack, GiftCardService $giftCardService)
    {
        $this->requestStack = $requestStack;
        $this->giftCardService = $giftCardService;
    }

    public function getPluginDescriptors(): array
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

    public function getCartTotalHTWhitoutGiftCart(): float|int
    {
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();
        $total = 0;

        if (null != $cart) {
            foreach ($cart->getCartItems() as $cartItem) {
                $product = $cartItem->getProduct();
                if ($product->getRef() === TheliaGiftCard::GIFT_CARD_CART_PRODUCT_REF) {
                    continue;
                }

                if ($cartItem->getPromo()) {
                    $total += $cartItem->getPromoPrice() * $cartItem->getQuantity();
                } else {
                    $total += $cartItem->getPrice() * $cartItem->getQuantity();
                }
            }
        }

        return $total;

    }

    public function getGitCardInfo($params,\Smarty_Internal_Template $smarty): void
    {
        //Récupération des informations liées à l'achat d'une carte cadeau (bénéficiare, message ...)

        $cartItemId = $params['cart_item_id'] ?? null;
        $code = $params['code'] ?? null;
        $infoGiftCard = null;

        $smarty->assign(['sponsor_name' => ""]);
        $smarty->assign(['beneficiary_name' => ""]);
        $smarty->assign(['beneficiary_message' => ""]);

        if ($cartItemId) {
            $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();

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

    public function isGiftCardProduct($params): bool
    {
        $productId = $params['product_id'];
        $tabProductGiftCard = TheliaGiftCard::getGiftCardProductList();

        if (in_array($productId, $tabProductGiftCard)) {
            return true;
        }

        return false;
    }

    public function wasGiftCardInCart(): bool
    {
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();
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

    public function getGiftCardCartAmount($params, \Smarty_Internal_Template $smarty): int|string|null
    {
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();
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

    public function resetGiftCardOncart(): void
    {
        if ($this->requestStack->getCurrentRequest()->hasSession()) {
            $this->giftCardService->reset();
        }
    }

    public function getOrderSessionPostage(\Smarty_Internal_Template $smarty): void
    {
        if ($this->requestStack->getCurrentRequest()->hasSession()) {
            $postage = $this->requestStack->getCurrentRequest()->getSession()->get(TheliaGiftCard::GIFT_CARD_SESSION_POSTAGE);
            $smarty->assign(['realPostageGiftCard' => $postage]);
        }
    }

    public function getGiftCardCategoryId(): int
    {
        return TheliaGiftCard::getGiftCardCategoryId();
    }
}