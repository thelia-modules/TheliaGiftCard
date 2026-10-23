<?php

declare(strict_types=1);

/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook\Theme;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Checkout\Enum\CheckoutDisplayMode;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;
use TheliaGiftCard\Service\GiftCardAmount;
use TheliaGiftCard\Service\GiftCardCartSpending;
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

    private const PAYMENT_STEP_ROUTE = 'checkout_payment';

    private const CHECKOUT_SUMMARY_TEMPLATE = '@TheliaGiftCardModule/frontOffice/flexy/TheliaGiftCard/checkout-summary-gift-card.html.twig';

    public function __construct(
        private Environment $twig,
        private RequestStack $requestStack,
        private EventDispatcherInterface $dispatcher,
        private GiftCardCartSpending $cartSpending,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return \in_array($hookName, ['account.bottom', 'product.bottom', 'checkout.summary.bottom'], true);
    }

    public function render(string $hookName, array $parameters): string
    {
        return match ($hookName) {
            'account.bottom' => $this->renderAccount(),
            'product.bottom' => $this->renderProduct($parameters),
            'checkout.summary.bottom' => $this->renderCheckoutSummary(),
            default => '',
        };
    }

    /**
     * Under the total of the checkout summary: the form that puts the customer's cards on the
     * cart, and what they pay and what is left to pay, the amount the payment module asks for.
     *
     * The form is left out on the payment step, where the `TheliaGiftCard:PaymentPart` component of the
     * `checkout.payment.parts` hook is the one entry: the lines stay, the form is drawn on the other steps.
     *
     * Only for a signed-in customer who has a card to spend or already put one on the cart, once
     * a carrier is chosen: the cards pay the postage too, so the total is known only then.
     */
    private function renderCheckoutSummary(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        if (!$session instanceof Session || !($customer = $session->getCustomerUser()) instanceof Customer) {
            return '';
        }

        $cart = $session->getSessionCart($this->dispatcher);

        if (null === $cart->getId() || null === $cart->getDeliveryModuleId()) {
            return '';
        }

        $cards = $this->cartSpending->spendableCards($customer, $cart);
        $paidWithCards = $this->cartSpending->amountOnCart($cart);

        if ([] === $cards && 0 === $paidWithCards) {
            return '';
        }

        // The total the core composes the parts of the order from (OrderPaymentComposer): what is
        // read here is what the core plans for the cards and leaves for the payment module.
        $cartTotal = $this->cartSpending->cartTotal($cart);

        return $this->twig->render(self::CHECKOUT_SUMMARY_TEMPLATE, [
            'cards' => $cards,
            'withForm' => !$this->isOnPaymentStep($request),
            'paidWithCards' => GiftCardAmount::decimal(GiftCardAmount::paidByCards($cartTotal, $paidWithCards)),
            'leftToPay' => GiftCardAmount::decimal(GiftCardAmount::leftToPay($cartTotal, $paidWithCards)),
        ]);
    }

    /**
     * The summary is a live component: drawn with the page it sits on, then redrawn by the /_components
     * endpoint, where the route of the request is the endpoint and the page is in the Referer. On a
     * one-page checkout the payment is a section of the screen served by the cart route.
     */
    private function isOnPaymentStep(?Request $request): bool
    {
        if (CheckoutDisplayMode::OnePage === CheckoutDisplayMode::fromStoredValue(ConfigQuery::getCheckoutDisplayMode())) {
            return true;
        }

        if (null === $request) {
            return false;
        }

        if (self::PAYMENT_STEP_ROUTE === $request->attributes->get('_route')) {
            return true;
        }

        $referer = (string) $request->headers->get('Referer', '');

        if (!$request->attributes->has('_live_component') || '' === $referer) {
            return false;
        }

        try {
            return $this->urlGenerator->generate(self::PAYMENT_STEP_ROUTE) === parse_url($referer, \PHP_URL_PATH);
        } catch (RoutingException) {
            return false;
        }
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
