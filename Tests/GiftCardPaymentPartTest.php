<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\LiveComponent\EventListener\LiveComponentSubscriber;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ConsentQuery;
use Thelia\Model\Customer;
use Thelia\Model\Map\CartTableMap;
use Thelia\Model\Map\ConsentTableMap;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Model\OrderQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardActivationLimiter;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The gift cards on the payment step of the Flexy theme, from a browser's point of view: the page
 * the theme draws, the live component the module draws in its `checkout.payment.parts` line, the
 * actions of that component, and the order the core places afterwards. The cart is one virtual
 * product worth 100.00, delivered by the virtual product delivery module.
 */
final class GiftCardPaymentPartTest extends WebIntegrationTestCase
{
    private const string COMPONENT = 'TheliaGiftCard:PaymentPart';

    private const string CLIENT_IP = '127.0.0.1';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    /** @var list<int> */
    private array $customerIds = [];

    private bool $providerInstalled = false;

    private bool $otherInstantMeansInstalled = false;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || 1 !== preg_match('/(?:_test|^test(?:_\d+)?)$/', $databaseName)) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database (or test, test_2… of the Thelia monorepo).', (string) $databaseName));
        }

        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        ConsentTableMap::clearInstancePool();
        foreach (ConsentQuery::create()->filterByActive(1)->find($this->getPropelConnection()) as $consent) {
            $consent->setActive(0)->save($this->getPropelConnection());
        }
        $this->getService(ConsentProvider::class)->forgetCache();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        foreach ($this->customerIds as $customerId) {
            $this->limiter(GiftCardActivationLimiter::PER_CUSTOMER_LIMITER)->create('customer:'.$customerId)->reset();
        }
        $this->limiter(GiftCardActivationLimiter::PER_CLIENT_LIMITER)->create('client:'.self::CLIENT_IP)->reset();
        $this->customerIds = [];

        if ($this->otherInstantMeansInstalled) {
            static::getContainer()->set('module.'.StubRefusingInstantPaymentModule::getModuleCode(), null);
            StubRefusingInstantPaymentModule::reset();
            $this->otherInstantMeansInstalled = false;
        }

        if ($this->providerInstalled) {
            static::getContainer()->set('module.'.StubProviderPaymentModule::getModuleCode(), null);
            StubProviderPaymentModule::reset();
            $this->providerInstalled = false;
        }

        parent::tearDown();
    }

    public function testACodeTypedAtThePaymentStepPutsTheCardOnTheCartAndTheStepShowsThePartAndWhatIsLeft(): void
    {
        // A provider that charges the balance, the one the buyer picked for what the card does not pay.
        $provider = $this->installProvider();
        [$cart, $customer] = $this->cartWorth100((int) $provider->getId());
        $giftCard = $this->giftCard(['amount' => '30']);

        $response = $this->liveAction('applyCode', [], ['code' => $giftCard->getCode()]);

        self::assertSame(['30.00'], $this->amountsOnCart($cart));
        self::assertSame($customer->getId(), $this->reread($giftCard)->getBeneficiaryCustomerId(), 'The code activates the card on the buyer\'s account.');
        self::assertStringContainsString('PAYMENT_PARTS_CHANGED', $response->html(), 'The step is told to read the payment composition again.');

        $parts = $this->partsOf($this->requestThePaymentStep());
        self::assertStringContainsString('Left to pay', $parts);
        self::assertStringContainsString('70.00', $parts);
        self::assertStringContainsString('30.00', $parts);
        self::assertSame((int) $provider->getId(), $this->reread($cart)->getPaymentModuleId(), 'The module of the cart is the theme\'s to set: a part that does not cover leaves the provider alone.');
    }

    public function testACardOfTheBuyerIsTickedThenUntickedFromItsCheckbox(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $giftCard = $this->giftCard(['amount' => '30', 'beneficiaryCustomerId' => $customer->getId()]);

        $component = $this->component();
        $checkbox = $component->filter(\sprintf('input[type=checkbox][data-live-action-param="toggle"][data-live-gift-card-id-param="%d"]', $giftCard->getId()));
        self::assertCount(1, $checkbox, 'One checkbox per card, carrying the id of the card as the argument of the live action.');
        self::assertNull($checkbox->attr('checked'));

        $ticked = $this->liveAction('toggle', ['giftCardId' => (int) $giftCard->getId()]);

        self::assertSame(['30.00'], $this->amountsOnCart($cart));
        self::assertNotNull($ticked->filter('input[data-live-gift-card-id-param="'.$giftCard->getId().'"]')->attr('checked'), 'The card comes back ticked.');

        $this->liveAction('toggle', ['giftCardId' => (int) $giftCard->getId()]);

        self::assertSame([], $this->amountsOnCart($cart));
    }

    public function testACardThatCoversTheOrderLeavesNoProviderThenTheCoreTakesThePaymentOfTheOrder(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $giftCard = $this->giftCard(['amount' => '150', 'beneficiaryCustomerId' => $customer->getId()]);
        $module = $this->giftCardModule();

        $this->liveAction('toggle', ['giftCardId' => (int) $giftCard->getId()]);

        self::assertSame(['100.00'], $this->amountsOnCart($cart), 'The card pays what the cart costs, no more.');

        $step = $this->requestThePaymentStep();
        self::assertStringContainsString('Nothing is left to pay', $step->text());
        self::assertCount(0, $step->filter('.PaymentCard-content'), 'No provider is offered for an order that is paid.');
        self::assertSame((int) $module->getId(), $this->reread($cart)->getPaymentModuleId(), 'The theme makes the gift card module the payment module of a cart it pays whole.');

        $this->client->request('GET', '/checkout/pay');

        self::assertResponseRedirects('/checkout/confirm');
        $order = $this->orderOf($cart);
        self::assertTrue($this->reread($order)->isPaid(false), 'The core turns the order paid.');
        self::assertSame(
            [[(int) $module->getId(), 'settled']],
            array_map(
                static fn ($part): array => [$part->getPaymentModuleId(), $part->getState()],
                iterator_to_array(OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->find($this->getPropelConnection())),
            ),
        );
        self::assertSame(100.0, (float) $this->spentOn($giftCard));
    }

    public function testRemovingTheCardGivesTheWholeTotalBackAndTheCartLosesItsPaymentModule(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $giftCard = $this->giftCard(['amount' => '150', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->liveAction('toggle', ['giftCardId' => (int) $giftCard->getId()]);
        $this->requestThePaymentStep();
        self::assertNotNull($this->reread($cart)->getPaymentModuleId());

        $removed = $this->liveAction('remove', []);

        self::assertSame([], $this->amountsOnCart($cart));
        self::assertStringContainsString('PAYMENT_PARTS_CHANGED', $removed->html());
        $step = $this->requestThePaymentStep();
        self::assertStringNotContainsString('Nothing is left to pay', $step->text());
        self::assertStringNotContainsString('Left to pay', $step->text(), 'No part: nothing to subtract from the total.');
        self::assertNull($this->reread($cart)->getPaymentModuleId(), 'The theme empties the payment module of a cart the part no longer covers.');
        self::assertSame(0.0, $this->spentOn($giftCard), 'Putting a card on a cart spends nothing, and taking it off gives nothing back.');
    }

    /**
     * The summary of the step listens to `syncSummary` and to the events of the cart, not to
     * PAYMENT_PARTS_CHANGED: without the first one it keeps showing the cards and the discount
     * of before the action.
     */
    public function testEveryActionTellsTheSummaryToDrawItselfAgain(): void
    {
        [, $customer] = $this->cartWorth100();
        $giftCard = $this->giftCard(['amount' => '30', 'beneficiaryCustomerId' => $customer->getId()]);
        $typed = $this->giftCard(['amount' => '20']);

        $responses = [
            'toggle' => $this->liveAction('toggle', ['giftCardId' => (int) $giftCard->getId()]),
            'applyCode' => $this->liveAction('applyCode', [], ['code' => $typed->getCode()]),
            'remove' => $this->liveAction('remove', []),
        ];

        foreach ($responses as $action => $response) {
            self::assertStringContainsString('PAYMENT_PARTS_CHANGED', $response->html(), $action);
            self::assertStringContainsString('syncSummary', $response->html(), $action);
        }
    }

    public function testACodeThatIsNotAUsableOneIsRefusedWithoutShowingTheBalanceOfACardOfSomeoneElse(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $other = $this->factory->customer($this->factory->customerTitle());
        $othersCard = $this->giftCard(['amount' => '123.45', 'beneficiaryCustomerId' => $other->getId()]);
        $expired = $this->giftCard(['amount' => '67.89', 'expirationDate' => new \DateTime('-1 day')]);
        $inactive = $this->giftCard(['amount' => '45.67', 'status' => 0]);
        $messages = [];

        foreach ([$othersCard->getCode(), $expired->getCode(), $inactive->getCode(), 'NOSUCHCODE'] as $code) {
            $response = $this->liveAction('applyCode', [], ['code' => $code]);
            $messages[] = $response->filter('[role=alert], .Input-error')->each(static fn (Crawler $node): string => trim($node->text()));
            self::assertStringNotContainsString('123.45', $response->text());
            self::assertStringNotContainsString('67.89', $response->text());
            self::assertStringNotContainsString('45.67', $response->text());
        }

        self::assertSame([], $this->amountsOnCart($cart));
        $field = $response->filter('input#gift-card-code');
        self::assertSame('true', $field->attr('aria-invalid'), 'The field is marked invalid.');
        self::assertSame('gift-card-code-error', $field->attr('aria-describedby'));
        self::assertCount(1, $response->filter('#gift-card-code-error'), 'The message the field points at exists.');
        self::assertNotSame([], $messages[0], 'The buyer is told the code is refused.');
        self::assertSame([$messages[0], $messages[0], $messages[0], $messages[0]], $messages, 'The same words whatever is wrong with the code.');
        self::assertSame($other->getId(), $this->reread($othersCard)->getBeneficiaryCustomerId(), 'A card held by someone else stays theirs.');
        self::assertNull($this->reread($expired)->getBeneficiaryCustomerId());
    }

    public function testACardOfAnotherCustomerCannotBeTickedByItsIdAndTheCardsAlreadyOnTheCartStay(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $mine = $this->giftCard(['amount' => '30', 'beneficiaryCustomerId' => $customer->getId()]);
        $other = $this->factory->customer($this->factory->customerTitle());
        $othersCard = $this->giftCard(['amount' => '123.45', 'beneficiaryCustomerId' => $other->getId()]);
        $this->liveAction('toggle', ['giftCardId' => (int) $mine->getId()]);

        $response = $this->liveAction('toggle', ['giftCardId' => (int) $othersCard->getId()]);

        self::assertSame(['30.00'], $this->amountsOnCart($cart), 'Neither the other card on the cart, nor mine off it.');
        self::assertStringNotContainsString('123.45', $response->text());
        self::assertGreaterThanOrEqual(1, $response->filter('[role=alert]')->count());
    }

    /**
     * The only protection against a cross-site request is the header of an ajax one, which the
     * LiveComponentSubscriber demands and a browser lets no other site add to a request without a
     * preflight. The test kernel runs the subscriber in its test mode, where the header is not
     * asked for: a subscriber of the same container is built again outside that mode to see what
     * production does with the very request the browser sends, and with the one a forged form sends.
     */
    public function testALiveActionIsNotTakenOverWithoutTheHeaderOfAnAjaxRequest(): void
    {
        $this->cartWorth100();
        $component = $this->component();
        $url = \sprintf('%s/%s', $component->attr('data-live-url-value'), 'toggle');
        $payload = ['data' => json_encode([
            'props' => json_decode((string) $component->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR),
            'updated' => new \stdClass(),
            'args' => ['giftCardId' => 1],
        ], \JSON_THROW_ON_ERROR)];

        self::assertFalse($this->productionSubscriberTakesOver($url, $payload, ajax: false), 'A form posted from another site carries no such header.');
        self::assertTrue($this->productionSubscriberTakesOver($url, $payload, ajax: true), 'The request of the component itself is served.');
    }

    public function testTheCodesTypedAreLimitedAndTheLimitIsTheSameWhateverTheCode(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $limit = 10;

        for ($attempt = 1; $attempt <= $limit; ++$attempt) {
            $this->liveAction('applyCode', [], ['code' => 'WRONG'.$attempt]);
        }

        $giftCard = $this->giftCard(['amount' => '30']);
        $response = $this->liveAction('applyCode', [], ['code' => $giftCard->getCode()]);

        self::assertSame([], $this->amountsOnCart($cart), 'Past the limit, even the right code is not looked up.');
        self::assertNull($this->reread($giftCard)->getBeneficiaryCustomerId());
        self::assertStringContainsString('Too many codes were tried', $response->text());
    }

    /**
     * The theme calls the hook once per instant means with the same name: the line of another
     * means must not carry the controls of the gift cards.
     */
    public function testTheLineOfAnotherInstantMeansHoldsNoGiftCardControl(): void
    {
        $this->cartWorth100();
        $other = $this->installInstantMeans(StubRefusingInstantPaymentModule::class);
        $this->otherInstantMeansInstalled = true;
        $giftCardModule = $this->giftCardModule();
        self::assertGreaterThan($giftCardModule->getPosition(), $other->getPosition());

        $lines = $this->requestThePaymentStep()->filter('section[aria-labelledby="payment-parts-title"] > ul > li');

        self::assertCount(2, $lines, 'One line per instant means.');
        $controls = $lines->each(static fn (Crawler $line): int => $line->filter(\sprintf('[data-live-name-value="%s"]', self::COMPONENT))->count());
        self::assertSame(1, array_sum($controls), 'The controls are drawn once.');
        self::assertSame(1, $controls[0], 'In the line of the gift cards, which comes first.');
    }

    /**
     * The step draws the component of the module as a child of its own live component. A child is
     * sent back as an empty placeholder, kept as the browser has it, unless a prop it accepts from
     * its parent changed: without `updateFromParent` its fingerprint is empty, and a card put on
     * the cart from elsewhere would never show in the boxes.
     */
    public function testTheComponentIsDrawnAgainWhenTheStepChangedWithoutIt(): void
    {
        [$cart, $customer] = $this->cartWorth100();
        $giftCard = $this->giftCard(['amount' => '30', 'beneficiaryCustomerId' => $customer->getId()]);
        $page = $this->requestThePaymentStep();
        $parent = $page->filter('[data-live-url-value][data-live-name-value="Organisms:Payment:Base"]')->first();
        $child = $page->filter(\sprintf('[data-live-url-value][data-live-name-value="%s"]', self::COMPONENT))->first();
        self::assertCount(1, $parent);
        self::assertCount(1, $child);
        $fingerprint = (string) $child->attr('data-live-fingerprint-value');
        self::assertNotSame('', $fingerprint, 'The child is fingerprinted by the prop its parent gives it.');

        // Done elsewhere than in the component: another tab, another module.
        (new GiftCardCart())->setCartId($cart->getId())->setGiftCardId($giftCard->getId())->setSpendAmount('30')->save($this->getPropelConnection());

        $this->client->request(
            'POST',
            \sprintf('%s/readThePartsAgain', $parent->attr('data-live-url-value')),
            ['data' => json_encode([
                'props' => json_decode((string) $parent->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR),
                'updated' => new \stdClass(),
                'args' => new \stdClass(),
                'children' => [(string) $child->attr('id') => ['fingerprint' => $fingerprint, 'tag' => 'div']],
            ], \JSON_THROW_ON_ERROR)],
            [],
            ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
        self::assertResponseIsSuccessful();
        $again = (new Crawler((string) $this->client->getResponse()->getContent()))->filter(\sprintf('[id="%s"]', $child->attr('id')));

        self::assertCount(1, $again);
        self::assertNotSame($fingerprint, $again->attr('data-live-fingerprint-value'), 'The new amount changes the fingerprint.');
        self::assertNotNull($again->attr('data-live-props-updated-from-parent-value'), 'The browser is told to draw the child again with the new amount.');
    }

    public function testACodeIsNotAttachedToTheBuyerWhenTheCartCannotTakeACard(): void
    {
        [$cart] = $this->cartWorth100();
        // Drawn while the cart is still payable: the page of a cart that holds a gift card goes elsewhere.
        $component = $this->component();
        $category = $this->factory->category();
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, (string) $category->getId(), false, true);
        $product = $this->factory->product($category, $this->factory->taxRule(), $this->factory->currency(), ['baseQuantity' => 100]);
        $this->factory->cartItem($cart, $product, overrides: ['price' => '50.000000', 'promoPrice' => '50.000000']);
        $giftCard = $this->giftCard(['amount' => '30']);

        $response = $this->liveAction('applyCode', [], ['code' => $giftCard->getCode()], component: $component);

        self::assertNull($this->reread($giftCard)->getBeneficiaryCustomerId(), 'The card stays where it was: it is not attached to an account that cannot use it.');
        self::assertSame([], $this->amountsOnCart($cart));
        self::assertStringContainsString('This gift card cannot be used on this order.', $response->text());
        self::assertStringNotContainsString('This code cannot be used', $response->text(), 'The code is not what is wrong.');
    }

    public function testAGuestIsOfferedNothingToSpend(): void
    {
        [$cart] = $this->cartWorth100();
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $cart->setCustomerId($guest->getId())->save($this->getPropelConnection());
        $this->injector->sessionOf($guest, $cart);

        $component = $this->component();

        self::assertCount(0, $component->filter('input[type=checkbox]'));
        self::assertCount(0, $component->filter('form'));
        self::assertStringContainsString('Sign in to pay with a gift card', $component->text());
    }

    /**
     * @return array{0: Cart, 1: Customer}
     */
    private function cartWorth100(?int $paymentModuleId = null): array
    {
        $connection = $this->getPropelConnection();
        $country = $this->factory->country();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->customerIds[] = (int) $customer->getId();
        $this->limiter(GiftCardActivationLimiter::PER_CUSTOMER_LIMITER)->create('customer:'.$customer->getId())->reset();
        $this->limiter(GiftCardActivationLimiter::PER_CLIENT_LIMITER)->create('client:'.self::CLIENT_IP)->reset();

        $address = $this->factory->address($customer, $country);
        $cart = $this->factory->cart($customer);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency(), ['baseQuantity' => 100]);
        $product->setVirtual(1)->save($connection);
        $this->factory->cartItem($cart, $product, overrides: ['price' => '100.000000', 'promoPrice' => '100.000000']);

        $deliveryModule = ModuleQuery::create()->findOneByCode('VirtualProductDelivery') ?? self::fail('No VirtualProductDelivery module: run bin/test-prepare.');

        $cart
            ->setAddressDeliveryId($this->factory->cartAddress($address, $country)->getId())
            ->setAddressInvoiceId($this->factory->cartAddress($address, $country)->getId())
            ->setDeliveryModuleId($deliveryModule->getId())
            ->setPaymentModuleId($paymentModuleId)
            ->setPostage('0')
            ->setPostageTax('0')
            ->save($connection);

        $this->injector->sessionOf($customer, $cart);

        return [$cart, $customer];
    }

    private function giftCard(array $overrides = []): GiftCard
    {
        $giftCard = (new GiftCard())
            ->setCode($overrides['code'] ?? strtoupper(bin2hex(random_bytes(5))))
            ->setAmount((string) ($overrides['amount'] ?? '50'))
            ->setSpendAmount('0')
            ->setStatus($overrides['status'] ?? 1)
            ->setExpirationDate($overrides['expirationDate'] ?? new \DateTime('+6 months'))
            ->setBeneficiaryCustomerId($overrides['beneficiaryCustomerId'] ?? null);
        $giftCard->save($this->getPropelConnection());

        return $giftCard;
    }

    /**
     * The module's component as the payment step draws it: the page, then the element the Stimulus
     * controller reads the url and the props of.
     */
    private function component(): Crawler
    {
        $component = $this->requestThePaymentStep()->filter(\sprintf('[data-live-url-value][data-live-name-value="%s"]', self::COMPONENT))->first();
        self::assertCount(1, $component, 'The payment step must draw the live component of the module, in the checkout.payment.parts line.');

        return $component;
    }

    /**
     * One action of the component, the way the Stimulus controller sends it.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $updated   the writable props the buyer changed
     */
    private function liveAction(string $action, array $arguments, array $updated = [], bool $ajax = true, ?Crawler $component = null): Crawler
    {
        $component ??= $this->component();
        $server = ['HTTP_ACCEPT' => 'application/vnd.live-component+html'];

        if ($ajax) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        $this->client->request(
            'POST',
            \sprintf('%s/%s', $component->attr('data-live-url-value'), $action),
            ['data' => json_encode([
                'props' => json_decode((string) $component->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR),
                'updated' => [] === $updated ? new \stdClass() : $updated,
                'args' => [] === $arguments ? new \stdClass() : $arguments,
            ], \JSON_THROW_ON_ERROR)],
            [],
            $server,
        );

        if ($ajax) {
            self::assertResponseIsSuccessful();
        }

        return new Crawler((string) $this->client->getResponse()->getContent());
    }

    /**
     * The text of the block of the parts: what the buyer reads of the cards and of what is left.
     */
    private function partsOf(Crawler $step): string
    {
        $parts = $step->filter('section[aria-labelledby="payment-parts-title"]');
        self::assertCount(1, $parts, 'The step must draw the block of the parts.');

        return (string) preg_replace('/\s+/', ' ', $parts->text());
    }

    /**
     * @param array<string, string> $payload
     */
    private function productionSubscriberTakesOver(string $url, array $payload, bool $ajax): bool
    {
        $testSubscriber = static::getContainer()->get('ux.live_component.event_subscriber');
        self::assertInstanceOf(LiveComponentSubscriber::class, $testSubscriber);
        $locator = (new \ReflectionProperty(LiveComponentSubscriber::class, 'container'))->getValue($testSubscriber);
        $subscriber = new LiveComponentSubscriber($locator, false);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $server = ['HTTP_ACCEPT' => 'application/vnd.live-component+html'];
        if ($ajax) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $request = Request::create($url, 'POST', $payload, [], [], $server);
        $request->attributes->add($router->match(parse_url($url, \PHP_URL_PATH) ?: $url));

        $subscriber->onKernelRequest(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        return $request->attributes->has('_controller');
    }

    private function requestThePaymentStep(): Crawler
    {
        $crawler = $this->client->request('GET', '/checkout/payment');

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string> what each card puts on the cart, in the order of the rows
     */
    private function amountsOnCart(Cart $cart): array
    {
        return array_values(array_map(
            static fn ($row): string => number_format((float) $row->getSpendAmount(), 2, '.', ''),
            iterator_to_array(GiftCardCartQuery::create()->filterByCartId($cart->getId())->orderById()->find($this->getPropelConnection())),
        ));
    }

    private function spentOn(GiftCard $giftCard): float
    {
        $statement = $this->getPropelConnection()->query('SELECT spend_amount FROM gift_card WHERE id = '.(int) $giftCard->getId());
        $spent = $statement->fetchColumn();
        $statement->close();

        return (float) $spent;
    }

    private function reread(Cart|GiftCard|Order $record): Cart|GiftCard|Order
    {
        $connection = $this->getPropelConnection();

        return match (true) {
            $record instanceof Cart => $this->rereadCart($record),
            $record instanceof GiftCard => GiftCardQuery::create()->findPk($record->getId(), $connection) ?? self::fail('The card went away.'),
            default => OrderQuery::create()->findPk($record->getId(), $connection) ?? self::fail('The order went away.'),
        };
    }

    private function rereadCart(Cart $cart): Cart
    {
        CartTableMap::clearInstancePool();
        CartTableMap::clearRelatedInstancePool();

        return CartQuery::create()->findPk($cart->getId(), $this->getPropelConnection()) ?? self::fail('The cart went away.');
    }

    private function orderOf(Cart $cart): Order
    {
        return OrderQuery::create()->filterByCartId($cart->getId())->findOne($this->getPropelConnection())
            ?? self::fail('No order was placed from the cart.');
    }

    /**
     * A payment provider that can charge a balance, active for the test only.
     */
    private function installProvider(): Module
    {
        $this->providerInstalled = true;

        return $this->installInstantMeans(StubProviderPaymentModule::class);
    }

    /**
     * @param class-string<BaseModule> $moduleClass
     */
    private function installInstantMeans(string $moduleClass): Module
    {
        $module = (new Module())
            ->setCode($moduleClass::getModuleCode())
            ->setFullNamespace($moduleClass)
            ->setVersion('1.0.0')
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setCategory('payment')
            ->setActivate(BaseModule::IS_ACTIVATED);
        $module->save($this->getPropelConnection());

        $instance = new $moduleClass();
        $instance->setContainer(static::getContainer());
        static::getContainer()->set('module.'.$moduleClass::getModuleCode(), $instance);

        return $module;
    }

    private function giftCardModule(): Module
    {
        return ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE) ?? self::fail('The gift card module is not registered: run bin/test-prepare.');
    }

    private function limiter(string $serviceId): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get($serviceId);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }
}
