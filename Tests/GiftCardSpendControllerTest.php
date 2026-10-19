<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Payment\OrderPaymentComposer;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\GiftWrapping;
use Thelia\Model\Lang;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use TheliaGiftCard\Hook\Theme\GiftCardThemeHook;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Spending a card at checkout: the form of the summary posts to /gift-card/spend, the card is
 * put on the cart, and the summary shows what it pays and what is left to pay.
 */
final class GiftCardSpendControllerTest extends GiftCardTestCase
{
    private Session $session;

    private bool $providerInstalled = false;

    protected function tearDown(): void
    {
        if ($this->providerInstalled) {
            static::getContainer()->set('module.'.StubProviderPaymentModule::getModuleCode(), null);
            $this->providerInstalled = false;
        }

        parent::tearDown();
    }

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

    /**
     * The gift wrapping is a line of the order the payment module is asked to be paid for: the
     * cards are capped on a total that holds it, and the summary leaves it to pay.
     */
    public function testTheGiftWrappingIsPartOfWhatTheCardsPayAndOfWhatIsLeftToPay(): void
    {
        [$customer, $cart] = $this->cartOf(50.0, '5');
        $this->wrap($cart, '4');
        $smallCard = $this->giftCard(['amount' => '20', 'beneficiaryCustomerId' => $customer->getId()]);

        $this->postSpend($customer, $cart, [(string) $smallCard->getId()], '20');
        $html = $this->renderSummaryHook($customer, $cart);

        self::assertMatchesRegularExpression('/data-gift-card-left-to-pay>.*?39[.,]00/s', $html);

        $bigCard = $this->giftCard(['amount' => '100', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->postSpend($customer, $cart, [(string) $bigCard->getId()], '100');
        $html = $this->renderSummaryHook($customer, $cart);

        self::assertSame('59.00', $this->amountOnCart($cart), 'The card pays the wrapping too.');
        self::assertMatchesRegularExpression('/data-gift-card-paid>.*?59[.,]00/s', $html);
        self::assertMatchesRegularExpression('/data-gift-card-left-to-pay>.*?0[.,]00/s', $html);
    }

    /**
     * The cards the summary form puts on the cart are the part the core composes for the order:
     * what the summary says the cards pay and what is left is what OrderPaymentComposer plans
     * for the module and leaves for the balance, gift wrapping included.
     */
    public function testWhatTheSummaryReadsIsThePartTheCoreComposesForTheOrder(): void
    {
        [$customer, $cart] = $this->cartOf(50.0, '5');
        $this->wrap($cart, '4');
        $giftCardModuleId = TheliaGiftCard::getModuleId();
        $providerId = $this->installProvider();
        $composer = static::getContainer()->get(OrderPaymentComposer::class);
        self::assertInstanceOf(OrderPaymentComposer::class, $composer);

        $smallCard = $this->giftCard(['amount' => '20', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->postSpend($customer, $cart, [(string) $smallCard->getId()], '20');
        $html = $this->renderSummaryHook($customer, $cart);

        $composition = $composer->composeForCart($this->freshCart($cart), $providerId);
        self::assertCount(2, $composition->parts, 'The card, then the provider for the balance.');
        self::assertSame($giftCardModuleId, $composition->parts[0]->moduleId);
        self::assertTrue($composition->parts[0]->isInstant);
        self::assertSame('20.000000', $composition->parts[0]->amount);
        self::assertSame('20.00', $this->shown($html, 'data-gift-card-paid'));
        self::assertSame('39.000000', $composition->balance);
        self::assertSame('39.00', $this->shown($html, 'data-gift-card-left-to-pay'), 'The core leaves 39.00 to the provider: 50.00 + 5.00 of postage + 4.00 of wrapping - 20.00.');

        $bigCard = $this->giftCard(['amount' => '100', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->postSpend($customer, $cart, [(string) $bigCard->getId()], '100');
        $html = $this->renderSummaryHook($customer, $cart);

        $composition = $composer->composeForCart($this->freshCart($cart), $providerId);
        self::assertSame('59.000000', $composition->parts[0]->amount, 'The part is capped on the total with its wrapping.');
        self::assertSame('0.000000', $composition->balance);
        self::assertSame('59.00', $this->shown($html, 'data-gift-card-paid'));
        self::assertSame('0.00', $this->shown($html, 'data-gift-card-left-to-pay'));

        // The total goes down after the cards were put on the cart: the core caps the part on the
        // new total, and the summary says the same.
        $cart->setPostage('0')->save();
        $html = $this->renderSummaryHook($customer, $cart);

        $composition = $composer->composeForCart($this->freshCart($cart), $providerId);
        self::assertSame('59.00', $this->amountOnCart($cart), 'The cards on the cart are untouched.');
        self::assertSame('54.000000', $composition->parts[0]->amount, 'The core caps the part on the lower total: 50.00 + 4.00 of wrapping.');
        self::assertSame('0.000000', $composition->balance);
        self::assertSame('54.00', $this->shown($html, 'data-gift-card-paid'));
        self::assertSame('0.00', $this->shown($html, 'data-gift-card-left-to-pay'));
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

    private function wrap(Cart $cart, string $price): void
    {
        $giftWrapping = (new GiftWrapping())
            ->setCode('wrap-'.bin2hex(random_bytes(4)))
            ->setPrice($price)
            // Untaxed, as the lines of the cart: the amounts read as written.
            ->setTaxRuleId((int) $this->createFixtureFactory()->taxRule(['isDefault' => false])->getId())
            ->setActive(1);
        $giftWrapping->save();

        $cart->setGiftWrappingId($giftWrapping->getId())->save();
    }

    /**
     * The amount of the line of the summary that carries this marker, as a decimal with a point.
     */
    private function shown(string $html, string $marker): string
    {
        self::assertSame(1, preg_match('/'.preg_quote($marker, '/').'>.*?(\d+)[.,](\d{2})\b/s', $html, $matches), $marker.' is not in the summary.');

        return $matches[1].'.'.$matches[2];
    }

    private function installProvider(): int
    {
        $module = (new Module())
            ->setCode(StubProviderPaymentModule::getModuleCode())
            ->setFullNamespace(StubProviderPaymentModule::class)
            ->setVersion('1.0.0')
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setCategory('payment')
            ->setActivate(BaseModule::IS_ACTIVATED);
        $module->save($this->getPropelConnection());

        $instance = new StubProviderPaymentModule();
        $instance->setContainer(static::getContainer());
        static::getContainer()->set('module.'.StubProviderPaymentModule::getModuleCode(), $instance);
        $this->providerInstalled = true;

        return (int) $module->getId();
    }

    private function freshCart(Cart $cart): Cart
    {
        $fresh = CartQuery::create()->findPk($cart->getId());
        self::assertNotNull($fresh);

        return $fresh;
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
