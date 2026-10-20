<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Lang;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use TheliaGiftCard\Hook\Theme\GiftCardThemeHook;
use TheliaGiftCard\Model\GiftCardCartQuery;

/**
 * Spending a card at checkout: the form of the summary posts to /gift-card/spend, the card is
 * put on the cart, and the summary shows what it pays and what is left to pay.
 */
final class GiftCardSpendControllerTest extends GiftCardTestCase
{
    private Session $session;

    public function testTheCustomerPutsTheirCardOnTheCartAndGoesBackToThePaymentStep(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);

        $response = $this->postSpend($customer, $cart, [(string) $giftCard->getId()], '30');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/payment', (string) $response->headers->get('Location'));
        self::assertSame('30.00', $this->amountOnCart($cart));
    }

    public function testACardOfAnotherCustomerIsNotPutOnTheCart(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $fixtures = $this->createFixtureFactory();
        $someoneElse = $fixtures->customer($fixtures->customerTitle());
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $someoneElse->getId()]);

        $response = $this->postSpend($customer, $cart, [(string) $giftCard->getId()], '30');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/payment', (string) $response->headers->get('Location'));
        self::assertSame([true], array_column(array_values($this->session->getFormErrorInformation()), 'hasError'));
        self::assertSame(0, GiftCardCartQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testAVisitorWhoIsNotSignedInIsSentToTheLoginPage(): void
    {
        [, $cart] = $this->cartOf(90.0, '10');

        $response = $this->postSpend(null, $cart, [], '30');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/login', (string) $response->headers->get('Location'));
        self::assertSame(0, GiftCardCartQuery::create()->filterByCartId($cart->getId())->count());
    }

    public function testTheSummarySaysWhatTheCardPaysAndWhatIsLeftToPay(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->postSpend($customer, $cart, [(string) $giftCard->getId()], '30');

        $html = $this->renderSummaryHook($customer, $cart);

        self::assertMatchesRegularExpression('/data-gift-card-paid>.*?30[.,]00/s', $html);
        self::assertMatchesRegularExpression('/data-gift-card-left-to-pay>.*?70[.,]00/s', $html);
        self::assertStringContainsString('action="/gift-card/spend"', $html);
    }

    public function testTheSummaryShowsNothingBeforeACarrierIsChosen(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $cart->setDeliveryModuleId(null)->save();

        self::assertSame('', $this->renderSummaryHook($customer, $cart));
    }

    /**
     * @param list<string> $giftCardIds
     */
    private function postSpend(?Customer $customer, Cart $cart, array $giftCardIds, string $amount): Response
    {
        $this->session = $this->sessionOf($customer, $cart);

        $request = Request::create('/gift-card/spend', 'POST');
        $request->setSession($this->session);
        $request->request->set('consume_gift_card', [
            'gift_card_ids' => $giftCardIds,
            'amount_used' => $amount,
            '_token' => $this->csrfToken($request, 'consume_gift_card'),
        ]);

        return $this->handleAsMainRequest($request);
    }

    private function renderSummaryHook(Customer $customer, Cart $cart): string
    {
        $session = $this->sessionOf($customer, $cart);
        // A shop request always has them: the amounts are written in the currency of the session.
        $session->setCurrency(Currency::getDefaultCurrency());
        $session->setLang(Lang::getDefaultLanguage());

        $request = Request::create('/checkout/payment');
        $request->setSession($session);

        $requestStack = $this->requestStack();
        $requestStack->push($request);

        try {
            $hook = static::getContainer()->get(GiftCardThemeHook::class);
            self::assertInstanceOf(GiftCardThemeHook::class, $hook);

            return $hook->render('checkout.summary.bottom', ['summary' => []]);
        } finally {
            $requestStack->pop();
        }
    }

    private function sessionOf(?Customer $customer, Cart $cart): Session
    {
        $session = new Session(new MockArraySessionStorage());

        if (null !== $customer) {
            $session->setCustomerUser($customer);
        }
        $session->setSessionCart($cart);

        return $session;
    }

    private function amountOnCart(Cart $cart): string
    {
        $amount = '0.00';
        foreach (GiftCardCartQuery::create()->filterByCartId($cart->getId())->find() as $giftCardCart) {
            $amount = number_format((float) $amount + (float) $giftCardCart->getSpendAmount(), 2, '.', '');
        }

        return $amount;
    }

    /**
     * @return array{Customer, Cart}
     */
    private function cartOf(float $linesTotal, string $postage): array
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle());
        $cart = $fixtures->cart($customer);
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(['isDefault' => false]), $fixtures->currency(), ['basePrice' => $linesTotal]);
        $fixtures->cartItem($cart, $product, null, ['price' => (string) $linesTotal, 'promoPrice' => (string) $linesTotal]);
        $cart
            ->setAddressDeliveryId($fixtures->cartAddress()->getId())
            ->setDeliveryModuleId(ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->findOne()?->getId())
            ->setPostage($postage)
            ->save();

        return [$customer, $cart];
    }
}
