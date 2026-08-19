<?php

declare(strict_types=1);

/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook\Theme;

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Model\CategoryQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;
use TheliaGiftCard\TheliaGiftCard;
use Twig\Environment;

/**
 * Injects the gift-card blocks into a Twig front-office theme.
 *
 * Theme hooks, not the legacy BaseHook ones: the legacy points are rendered by the Smarty
 * {hook} tag and by the Twig hook() function, and a Thelia 3 front theme declares its
 * extension points with theme_hook() instead — Flexy does not call hook() anywhere. The
 * fragments below would never be rendered through the legacy system on such a theme.
 */
final readonly class GiftCardThemeHook implements ThemeHookInterface
{
    private const ACCOUNT_TEMPLATE = '@TheliaGiftCardModule/frontOffice/flexy/TheliaGiftCard/account-gift-card.html.twig';

    private const PRODUCT_TEMPLATE = '@TheliaGiftCardModule/frontOffice/flexy/TheliaGiftCard/product-additional-gift-card.html.twig';

    public function __construct(
        private Environment $twig,
        private RequestStack $requestStack,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return \in_array($hookName, ['account.bottom', 'product.bottom'], true);
    }

    public function render(string $hookName, array $parameters): string
    {
        return match ($hookName) {
            'account.bottom' => $this->renderAccount(),
            'product.bottom' => $this->renderProduct($parameters),
            default => '',
        };
    }

    /**
     * Nothing is shown until the shop tells the module which category holds its gift-card
     * products: without it there is no card to activate and nowhere to buy one.
     */
    private function renderAccount(): string
    {
        $category = CategoryQuery::create()->findPk(TheliaGiftCard::getGiftCardCategoryId());

        if (null === $category) {
            return '';
        }

        return $this->twig->render(self::ACCOUNT_TEMPLATE, [
            'giftCardCategoryUrl' => URL::getInstance()->absoluteUrl(
                $category->getRewrittenUrl($this->resolveLocale())
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function renderProduct(array $parameters): string
    {
        $productId = $this->resolveProductId($parameters['product'] ?? null);

        if (null === $productId || !\in_array($productId, TheliaGiftCard::getGiftCardProductList(), true)) {
            return '';
        }

        return $this->twig->render(self::PRODUCT_TEMPLATE, ['productId' => $productId]);
    }

    /**
     * The hook point passes whatever the theme has at hand: Flexy passes the product read from
     * the front API (an array), another theme may pass the model or the bare id.
     */
    private function resolveProductId(mixed $product): ?int
    {
        if (\is_int($product) || (\is_string($product) && ctype_digit($product))) {
            return (int) $product;
        }

        if (\is_array($product) && isset($product['id'])) {
            return (int) $product['id'];
        }

        if (\is_object($product) && method_exists($product, 'getId')) {
            return (int) $product->getId();
        }

        return null;
    }

    private function resolveLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && $request->hasSession()) {
            return $request->getSession()->getLang()->getLocale();
        }

        return LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US';
    }
}
