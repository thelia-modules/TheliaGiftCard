<?php

namespace TheliaGiftCard\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use TheliaGiftCard\Service\GiftCardFunctionsService;

class GiftCardTwigExtension extends AbstractExtension
{
    private GiftCardFunctionsService $giftCardFunctionsService;

    public function __construct(GiftCardFunctionsService $giftCardFunctionsService)
    {
        $this->giftCardFunctionsService = $giftCardFunctionsService;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_cart_total_ht_without_giftcard', [$this, 'getCartTotalHTWithoutGiftCart']),
            new TwigFunction('get_giftcard_info', [$this, 'getGitCardInfo']),
            new TwigFunction('is_giftcard_product', [$this, 'isGiftCardProduct']),
            new TwigFunction('was_giftcard_in_cart', [$this, 'wasGiftCardInCart']),
            new TwigFunction('get_giftcard_cart_amount', [$this, 'getGiftCardCartAmount']),
            new TwigFunction('reset_giftcard_on_cart', [$this, 'resetGiftCardOnCart']),
            new TwigFunction('get_order_session_postage', [$this, 'getOrderSessionPostage']),
            new TwigFunction('get_giftcard_category_id', [$this, 'getGiftCardCategoryId']),
        ];
    }

    public function getCartTotalHTWithoutGiftCart(): float|int
    {
        return $this->giftCardFunctionsService->getCartTotalHTWithoutGiftCart();
    }

    public function getGitCardInfo(array $params = []): array
    {
        return $this->giftCardFunctionsService->getGitCardInfo($params);
    }

    public function isGiftCardProduct(array $params = []): bool
    {
        return $this->giftCardFunctionsService->isGiftCardProduct($params);
    }

    public function wasGiftCardInCart(): bool
    {
        return $this->giftCardFunctionsService->wasGiftCardInCart();
    }

    public function getGiftCardCartAmount(array $params = []): array
    {
        return $this->giftCardFunctionsService->getGiftCardCartAmount($params);
    }

    public function resetGiftCardOnCart(): void
    {
        $this->giftCardFunctionsService->resetGiftCardOnCart();
    }

    public function getOrderSessionPostage(array $params = []): array
    {
        return $this->giftCardFunctionsService->getOrderSessionPostage($params);
    }

    public function getGiftCardCategoryId(): int
    {
        return $this->giftCardFunctionsService->getGiftCardCategoryId();
    }
}
