<?php

namespace TheliaGiftCard\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardFunctionsService
{
    private RequestStack $requestStack;
    private GiftCardService $giftCardService;

    public function __construct(RequestStack $requestStack, GiftCardService $giftCardService)
    {
        $this->giftCardService = $giftCardService;
        $this->requestStack = $requestStack;
    }

    public function getCartTotalHTWithoutGiftCart(): float|int
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

    /**
     * Renvoie les informations liées à une carte cadeau
     *
     * @param array $params Les paramètres de recherche (cart_item_id ou code)
     * @return array Tableau contenant les informations de la carte cadeau
     */
    public function getGitCardInfo(array $params = []): array
    {
        $result = [
            'sponsor_name' => "",
            'beneficiary_name' => "",
            'beneficiary_message' => ""
        ];

        $cartItemId = $params['cart_item_id'] ?? null;
        $code = $params['code'] ?? null;
        $infoGiftCard = null;

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
                return $result;
            }

            $infoGiftCard = GiftCardInfoCartQuery::create()->findOneByGiftCardId($giftCard->getId());
        }

        if ($infoGiftCard) {
            $result = [
                'sponsor_name' => $infoGiftCard->getSponsorName(),
                'beneficiary_name' => $infoGiftCard->getBeneficiaryName(),
                'beneficiary_message' => $infoGiftCard->getBeneficiaryMessage()
            ];
        }

        return $result;
    }

    public function isGiftCardProduct(array $params = []): bool
    {
        $productId = $params['product_id'] ?? null;

        if (!$productId) {
            return false;
        }

        if (in_array($productId, TheliaGiftCard::getGiftCardProductList())) {
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

    /**
     * Récupère le montant des cartes cadeau utilisé pour le panier
     *
     * @param array $params Les paramètres optionnels
     * @return array Les détails du montant des cartes cadeau
     */
    public function getGiftCardCartAmount(array $params = []): array
    {
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();
        $total = 0;

        $giftCardsCart = GiftCardCartQuery::create()
            ->filterByCartId($cart->getId())
            ->find();

        foreach ($giftCardsCart as $giftCardCart) {
            $total += $giftCardCart->getSpendAmount();
        }

        return [
            'total' => $total,
            'gift_cards' => $giftCardsCart
        ];
    }

    public function resetGiftCardOnCart(): void
    {
        $this->giftCardService->resetGiftCardOnCart();
    }

    /**
     * Récupère les frais de port pour la session en cours
     *
     * @param array $params Les paramètres optionnels
     * @return array Les informations sur les frais de port
     */
    public function getOrderSessionPostage(array $params = []): array
    {
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $order = $session->getOrder();

        if (null === $order) {
            return [
                'amount' => 0,
                'tax' => 0,
                'amount_with_tax' => 0
            ];
        }

        return [
            'amount' => $order->getPostage(),
            'tax' => $order->getPostageTax(),
            'amount_with_tax' => $order->getPostage() + $order->getPostageTax()
        ];
    }

    public function getGiftCardCategoryId(): int
    {
        return TheliaGiftCard::getGiftCardCategoryId();
    }

}
