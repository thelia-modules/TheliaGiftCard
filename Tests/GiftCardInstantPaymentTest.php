<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Checkout\DTO\OrderPaymentRequest;
use Thelia\Domain\Checkout\Service\CheckoutPaymentService;
use Thelia\Domain\Checkout\Service\ConsentProvider;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Domain\Payment\Exception\InstantPaymentRefusedException;
use Thelia\Model\Area;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\ConsentQuery;
use Thelia\Model\CountryArea;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Model\OrderPostage;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BaseModule;
use TheliaGiftCard\EventListener\OrderAfterPayListener;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The gift cards as an instant payment means of the core: the buyer puts cards on the cart, the core
 * asks what they pay when it places the order, settles the part when the order is paid and releases
 * it when the order is cancelled. Nothing is stubbed on the gift card side: the module is the one
 * `bin/test-prepare` activated, the order is placed and paid again through the checkout service of
 * the theme and the API. The cart is worth 100.00 and its postage is free.
 */
final class GiftCardInstantPaymentTest extends GiftCardTestCase
{
    /** @var list<array{0: string, 1: callable}> */
    private array $registeredListeners = [];

    /** @var list<class-string<BaseModule>> */
    private array $stubClasses = [];

    private Module $giftCardModule;

    private Module $providerModule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giftCardModule = ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE)
            ?? throw new \RuntimeException('The gift card module is not registered: run bin/test-prepare.');
        self::assertSame(BaseModule::IS_ACTIVATED, (int) $this->giftCardModule->getActivate());

        // Registered after the gift card module, so that the core settles the cards first.
        $this->providerModule = $this->installStub(StubProviderPaymentModule::class);
        $this->installStub(StubRefusingInstantPaymentModule::class);

        foreach (ConsentQuery::create()->filterByMandatory(1)->find($this->getPropelConnection()) as $consent) {
            $consent->setActive(0)->save($this->getPropelConnection());
        }
        $this->consentProvider()->forgetCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->registeredListeners as [$eventName, $listener]) {
            $this->dispatcher()->removeListener($eventName, $listener);
        }
        $this->registeredListeners = [];

        foreach ($this->stubClasses as $stubClass) {
            static::getContainer()->set('module.'.$stubClass::getModuleCode(), null);
        }
        StubProviderPaymentModule::reset();
        StubRefusingInstantPaymentModule::reset();
        $this->consentProvider()->forgetCache();
        Propel::disableInstancePooling();

        parent::tearDown();
    }

    public function testACardWorthLessThanTheTotalPaysItsShareAndTheProviderTheBalance(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '30');

        $order = $this->pay($cart);

        self::assertSame(
            [
                [$this->giftCardModule->getId(), '30.000000', OrderPaymentPartState::SETTLED->value, false],
                [$this->providerModule->getId(), '70.000000', OrderPaymentPartState::PENDING->value, true],
            ],
            $this->partsOf($order),
        );
        self::assertSame(30.0, $this->spentOn($giftCard));
        self::assertSame(['30.000000'], $this->spentFromCardsByOrder($order));
        self::assertSame(['70.000000'], StubProviderPaymentModule::$charged, 'The provider is asked for the balance only.');
        self::assertFalse($this->reread($order)->isPaid(false), 'The provider has not confirmed yet.');
    }

    public function testACardWorthExactlyTheTotalPaysTheOrderAndTheCoreTurnsItPaidOnce(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '100']);
        $this->putInCart($cart, $giftCard, '100');
        $paidStatusId = (int) OrderStatusQuery::getPaidStatus()->getId();
        $turnedPaid = 0;
        $this->listen(TheliaEvents::ORDER_UPDATE_STATUS, static function (OrderEvent $event) use (&$turnedPaid, $paidStatusId): void {
            if ($event->getStatus() === $paidStatusId) {
                ++$turnedPaid;
            }
        });

        $order = $this->pay($cart);

        $stored = $this->reread($order);
        self::assertTrue($stored->isPaid(false));
        self::assertSame(1, $turnedPaid, 'The core turns the order paid, once: the module sets no status.');
        self::assertSame($this->giftCardModule->getId(), $stored->getPaymentModuleId());
        self::assertSame(
            [[$this->giftCardModule->getId(), '100.000000', OrderPaymentPartState::SETTLED->value, true]],
            $this->partsOf($order),
        );
        self::assertSame(100.0, $this->spentOn($giftCard));
        self::assertSame([], StubProviderPaymentModule::$charged);
    }

    public function testCardsWorthMoreThanTheTotalAreSpentForTheTotalOnly(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $first = $this->giftCard(['amount' => '80']);
        $second = $this->giftCard(['amount' => '80']);
        $this->putInCart($cart, $first, '80');
        $this->putInCart($cart, $second, '40');

        $order = $this->pay($cart);

        self::assertTrue($this->reread($order)->isPaid(false));
        self::assertSame('100.000000', $this->partsOf($order)[0][1]);
        self::assertSame([80.0, 20.0], [$this->spentOn($first), $this->spentOn($second)]);
        self::assertSame(['80.000000', '20.000000'], $this->spentFromCardsByOrder($order));
    }

    public function testAnOrderWhoseCardPartIsSettledDoesNotSpendItAgainOnANewAttempt(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '30');

        $order = $this->pay($cart);
        $sameOrder = $this->pay($cart);

        self::assertSame($order->getId(), $sameOrder->getId());
        self::assertSame(30.0, $this->spentOn($giftCard));
        self::assertCount(1, $this->spentFromCardsByOrder($order));
    }

    public function testAPartRefusedByAnotherMeansGivesTheCardBackAndTheOrderStaysUnpaid(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '5']);
        $this->putInCart($cart, $giftCard, '30');
        StubRefusingInstantPaymentModule::$applied = '20';

        try {
            $this->pay($cart);
            self::fail('The payment must be stopped.');
        } catch (InstantPaymentRefusedException) {
        }

        $order = OrderQuery::create()->filterByCartId($cart->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($order);
        self::assertSame(5.0, $this->spentOn($giftCard), 'What the card spent before the refusal is given back.');
        self::assertSame([], $this->spentFromCardsByOrder($order));
        self::assertSame(
            [OrderPaymentPartState::PENDING->value, OrderPaymentPartState::PENDING->value, OrderPaymentPartState::PENDING->value],
            array_column($this->partsOf($order), 2),
        );
        self::assertSame([], StubProviderPaymentModule::$charged);
    }

    public function testACardDisabledSinceItWasPutInTheCartRefusesThePaymentAndSpendsNothing(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '30');
        $giftCard->setStatus(0)->save();

        try {
            $this->pay($cart);
            self::fail('The payment must be stopped.');
        } catch (InstantPaymentRefusedException) {
        }

        $order = OrderQuery::create()->filterByCartId($cart->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($order);
        self::assertSame(0.0, $this->spentOn($giftCard));
        self::assertSame([], $this->spentFromCardsByOrder($order));
        self::assertSame([], StubProviderPaymentModule::$charged);
    }

    public function testCancellingTheOrderGivesTheCardBackOnceWhateverTheNumberOfCancellations(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $other = $this->giftCard(['amount' => '50', 'spendAmount' => '7']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);
        self::assertSame(110.0, $this->spentOn($giftCard));

        $this->reread($order)->setCancelled($this->dispatcher());

        self::assertSame(10.0, $this->spentOn($giftCard), 'The card is back to what it was before the order.');
        self::assertSame([], $this->spentFromCardsByOrder($order));
        self::assertSame([OrderPaymentPartState::RELEASED->value], array_column($this->partsOf($order), 2));

        // The cancellation replayed, the release of the part asked again, the listener of the module
        // run again: nothing more is given back. The part asked again succeeds without crediting: its
        // rows are marked given back, which tells a replay from a release that sees nothing.
        $this->reread($order)->setCancelled($this->dispatcher());
        $this->assertReleaseOfThePartCreditsNothingMore($order, 10.0, $giftCard);
        $listener = static::getContainer()->get(OrderAfterPayListener::class);
        self::assertInstanceOf(OrderAfterPayListener::class, $listener);
        $listener->onOrderCancelGiftCard(new OrderEvent($this->reread($order)));

        self::assertSame(10.0, $this->spentOn($giftCard));
        self::assertSame(7.0, $this->spentOn($other), 'A card the order did not spend from is left alone.');
    }

    public function testAReleaseRunTwiceCreditsTheCardOnce(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);

        $this->releasePartAgain($order);
        self::assertSame(10.0, $this->spentOn($giftCard));

        $this->assertReleaseOfThePartCreditsNothingMore($order, 10.0, $giftCard);
    }

    public function testRefundingThenCancellingTheOrderCreditsTheCardOnce(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);
        $refunded = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_REFUNDED);
        self::assertNotNull($refunded);

        // The listener of the module gives the card back on the refund; the core leaves the part alone.
        $this->dispatcher()->dispatch((new OrderEvent($this->reread($order)))->setStatus((int) $refunded->getId()), TheliaEvents::ORDER_UPDATE_STATUS);
        self::assertSame(10.0, $this->spentOn($giftCard));

        // The core then asks the module to release the part, still settled: the rows were given back by
        // the refund (marked so, not deleted), the release finds nothing to credit and succeeds, and the
        // core turns the part released.
        $this->reread($order)->setCancelled($this->dispatcher());

        self::assertSame(10.0, $this->spentOn($giftCard), 'The card was given back on the refund, and is not given back again.');
        self::assertSame([], $this->spentFromCardsByOrder($order));
        self::assertSame([OrderPaymentPartState::RELEASED->value], array_column($this->partsOf($order), 2));
        self::assertNotContains((int) $order->getId(), $this->ordersWaitingForARelease());
    }

    public function testAnOrderRefundedCancelledThenTurnedUnpaidIsNotPaidForNothingByANewAttempt(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);
        $refunded = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_REFUNDED);
        $notPaid = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_NOT_PAID);
        self::assertNotNull($refunded);
        self::assertNotNull($notPaid);
        $this->dispatcher()->dispatch((new OrderEvent($this->reread($order)))->setStatus((int) $refunded->getId()), TheliaEvents::ORDER_UPDATE_STATUS);
        $this->reread($order)->setCancelled($this->dispatcher());
        self::assertSame([OrderPaymentPartState::RELEASED->value], array_column($this->partsOf($order), 2));

        // The back office, whose transitions are free, turns the order unpaid again; the buyer pays it again.
        $this->reread($order)->setStatusId((int) $notPaid->getId())->save($this->getPropelConnection());

        $second = $this->pay($cart);

        // The part of the first order was given back: it is not settled again, so the first order is not
        // turned paid by parts that look settled. The attempt places another order, which spends the card
        // for what it pays; the money moved once, for that order.
        self::assertNotSame($order->getId(), $second->getId());
        self::assertFalse($this->reread($order)->isPaid(false), 'An order whose card was given back is not paid for nothing.');
        self::assertSame([OrderPaymentPartState::RELEASED->value], array_column($this->partsOf($order), 2));
        self::assertSame(110.0, $this->spentOn($giftCard), 'The 100 spent again are those of the new order.');
        self::assertSame(['100.000000'], $this->spentFromCardsByOrder($second));
        self::assertSame([], $this->spentFromCardsByOrder($order));
    }

    public function testTheListenerOfTheModuleLeavesACancelledOrderWithPartsToTheCore(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);
        $canceled = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_CANCELED);
        self::assertNotNull($canceled);

        // Cancelled with no release of the core in between (a release it refused would leave the same).
        $this->reread($order)->setStatusId((int) $canceled->getId())->save($this->getPropelConnection());
        $listener = static::getContainer()->get(OrderAfterPayListener::class);
        self::assertInstanceOf(OrderAfterPayListener::class, $listener);
        $listener->onOrderCancelGiftCard(new OrderEvent($this->reread($order)));

        self::assertSame(110.0, $this->spentOn($giftCard), 'The credit around the part is the core\'s to give, through the part.');
        self::assertSame(['100.000000'], $this->spentFromCardsByOrder($order));
        self::assertSame([OrderPaymentPartState::SETTLED->value], array_column($this->partsOf($order), 2));
        self::assertContains((int) $order->getId(), $this->ordersWaitingForARelease(), 'The query of the README finds it.');
    }

    public function testRefundingACancelledOrderCreditsNothingMore(): void
    {
        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);

        $this->reread($order)->setCancelled($this->dispatcher());
        $refunded = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_REFUNDED);
        self::assertNotNull($refunded);
        $this->dispatcher()->dispatch((new OrderEvent($this->reread($order)))->setStatus((int) $refunded->getId()), TheliaEvents::ORDER_UPDATE_STATUS);

        self::assertSame(10.0, $this->spentOn($giftCard));
    }

    public function testTheAmountAppliedToTheCartIsWhatIsOnItAndDoesNotChangeOnceThePartIsSettled(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        self::assertSame('0', $this->giftCardModule()->getAmountAppliedToCart($cart));

        $this->putInCart($cart, $this->giftCard(['amount' => '50']), '30');
        $this->putInCart($cart, $this->giftCard(['amount' => '50']), '20.5');
        self::assertSame('50.50', $this->giftCardModule()->getAmountAppliedToCart($cart));

        $this->pay($cart);

        self::assertSame('50.50', $this->giftCardModule()->getAmountAppliedToCart($this->rereadCart($cart)), 'The core compares it with what the unpaid order was placed with.');
        self::assertSame('0', $this->giftCardModule()->getAmountAppliedToCart(new Cart()));
    }

    public function testTheModuleSetsNoStatusWhenItIsAskedToPay(): void
    {
        $order = $this->createFixtureFactory()->order();
        $statusId = $order->getStatusId();

        self::assertNull($this->giftCardModule()->pay($order));

        self::assertSame($statusId, $this->reread($order)->getStatusId());
    }

    public function testACardReadBeforeItIsSpentIsNotStaleOncePooledInstancesAreOn(): void
    {
        $cart = $this->cartPaidWith($this->providerModule);
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '30');
        Propel::enableInstancePooling();
        self::assertSame('0.000000', GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount());

        $this->pay($cart);

        self::assertSame('30.000000', GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount());
    }

    public function testTheModuleReadsItsPaymentServiceFromTheContainerAndWorksWithoutOne(): void
    {
        self::assertInstanceOf(GiftCardPaymentService::class, static::getContainer()->get(TheliaGiftCard::PAYMENT_SERVICE_ID));

        $cart = $this->cartPaidWith($this->giftCardModule);
        $giftCard = $this->giftCard(['amount' => '110', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '100');
        $order = $this->pay($cart);
        $part = OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->findOne($this->getPropelConnection());
        self::assertInstanceOf(OrderPaymentPart::class, $part);

        // Instantiated by hand, outside the kernel: no container to ask.
        $outsideTheContainer = new TheliaGiftCard();
        self::assertFalse($outsideTheContainer->hasContainer());
        $outsideTheContainer->releasePart($part);

        self::assertSame(10.0, $this->spentOn($giftCard));
    }

    /**
     * The query of the README itself, read from its SQL block and run as written: cancelled orders whose part of
     * the gift card module is still settled while rows are still spent.
     *
     * @return list<int>
     */
    private function ordersWaitingForARelease(): array
    {
        $readme = (string) file_get_contents(__DIR__.'/../README.md');
        self::assertSame(1, preg_match('/```sql\s*(SELECT o\.id.*?);\s*```/s', $readme, $matches), 'The README holds the query.');

        $statement = $this->getPropelConnection()->query($matches[1]);
        $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        $statement->close();

        return $ids;
    }

    private function assertReleaseOfThePartCreditsNothingMore(Order $order, float $spentOnTheCard, GiftCard $giftCard): void
    {
        $this->releasePartAgain($order);

        self::assertSame($spentOnTheCard, $this->spentOn($giftCard), 'A row given back is never credited again.');
        self::assertSame(['100.000000'], $this->givenBackToCardsByOrder($order), 'The row stays, marked given back.');
    }

    private function installStub(string $stubClass): Module
    {
        $module = (new Module())
            ->setCode($stubClass::getModuleCode())
            ->setFullNamespace($stubClass)
            ->setVersion('1.0.0')
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setCategory('payment')
            ->setActivate(BaseModule::IS_ACTIVATED);
        $module->save($this->getPropelConnection());

        $instance = new $stubClass();
        $instance->setContainer(static::getContainer());
        static::getContainer()->set('module.'.$stubClass::getModuleCode(), $instance);
        $this->stubClasses[] = $stubClass;

        return $module;
    }

    private function giftCardModule(): TheliaGiftCard
    {
        $instance = $this->giftCardModule->getPaymentModuleInstance(static::getContainer());
        self::assertInstanceOf(TheliaGiftCard::class, $instance);

        return $instance;
    }

    /**
     * The module is asked again to give back the part of the order, the way a second cancellation
     * would ask it.
     */
    private function releasePartAgain(Order $order): void
    {
        $part = OrderPaymentPartQuery::create()
            ->filterByOrderId($order->getId())
            ->filterByPaymentModuleId($this->giftCardModule->getId())
            ->findOne($this->getPropelConnection());
        self::assertInstanceOf(OrderPaymentPart::class, $part);

        $this->giftCardModule()->releasePart($part);
    }

    /**
     * Placed through the theme's service, which reuses the unpaid order of the cart when it still
     * describes it. The delivery address is the cart's own.
     */
    private function pay(Cart $cart): Order
    {
        $cart = $this->rereadCart($cart);
        $factory = $this->createFixtureFactory();

        $outcome = $this->getService(CheckoutPaymentService::class)->payAndReturnOutcome(
            new OrderPaymentRequest(
                $cart,
                (int) $cart->getAddressDeliveryId(),
                (int) $cart->getAddressInvoiceId(),
                (int) $cart->getDeliveryModuleId(),
                (int) $cart->getPaymentModuleId(),
                $factory->currency(),
                $factory->lang(),
            ),
        );

        return $outcome->placedOrder;
    }

    /**
     * @return list<array{0: int|null, 1: string|null, 2: string|null, 3: bool|null}> module, amount, state and primary flag of each part, in settlement order
     */
    private function partsOf(Order $order): array
    {
        $parts = OrderPaymentPartQuery::create()
            ->filterByOrderId($order->getId())
            ->orderByPosition()
            ->find($this->getPropelConnection());

        return array_values(array_map(
            static fn (OrderPaymentPart $part): array => [$part->getPaymentModuleId(), $part->getAmount(), $part->getState(), $part->getIsPrimary()],
            iterator_to_array($parts),
        ));
    }

    /**
     * @return list<string>
     */
    private function spentFromCardsByOrder(Order $order): array
    {
        return array_values(array_map(
            static fn ($row): string => (string) $row->getSpendAmount(),
            iterator_to_array(GiftCardOrderQuery::create()->filterByOrderId($order->getId())->filterByStatus(GiftCardPaymentService::STATUS_SPENT)->orderById()->find($this->getPropelConnection())),
        ));
    }

    /**
     * @return list<string> what the order gave back to its cards, by row
     */
    private function givenBackToCardsByOrder(Order $order): array
    {
        return array_values(array_map(
            static fn ($row): string => (string) $row->getSpendAmount(),
            iterator_to_array(GiftCardOrderQuery::create()->filterByOrderId($order->getId())->filterByStatus(GiftCardPaymentService::STATUS_GIVEN_BACK)->orderById()->find($this->getPropelConnection())),
        ));
    }

    private function rereadCart(Cart $cart): Cart
    {
        return CartQuery::create()->findPk($cart->getId(), $this->getPropelConnection())
            ?? throw new \RuntimeException('The cart went away.');
    }

    private function reread(Order $order): Order
    {
        return OrderQuery::create()->findPk($order->getId(), $this->getPropelConnection())
            ?? throw new \RuntimeException('The order went away.');
    }

    private function putInCart(Cart $cart, GiftCard $giftCard, string $amount): void
    {
        (new GiftCardCart())->setCartId($cart->getId())->setGiftCardId($giftCard->getId())->setSpendAmount($amount)->save();
    }

    private function spentOn(GiftCard $giftCard): float
    {
        $statement = $this->getPropelConnection()->query('SELECT spend_amount FROM gift_card WHERE id = '.(int) $giftCard->getId());
        $spent = $statement->fetchColumn();
        $statement->close();

        return (float) $spent;
    }

    private function cartPaidWith(Module $paymentModule): Cart
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country();
        $deliveryModule = ModuleQuery::create()->findOneByCode('CustomDelivery')
            ?? throw new \RuntimeException('No delivery module installed: run bin/test-prepare.');

        $area = (new Area())->setName('Gift card payment test area '.uniqid());
        $area->save($this->getPropelConnection());
        (new CountryArea())->setAreaId($area->getId())->setCountryId($country->getId())->save($this->getPropelConnection());
        (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($deliveryModule->getId())->save($this->getPropelConnection());

        $this->listen(TheliaEvents::MODULE_DELIVERY_GET_POSTAGE, static function (DeliveryPostageEvent $event): void {
            $event->setValidModule(true);
            $event->setPostage(new OrderPostage(0.0, 0.0, 'VAT'));
            $event->stopPropagation();
        });
        $this->listen(TheliaEvents::MODULE_PAYMENT_IS_VALID, static function (IsValidPaymentEvent $event): void {
            $event->setValidModule(true);
            $event->stopPropagation();
        });

        $customer = $factory->customer($factory->customerTitle());
        $factory->address($customer, $country);

        $product = $factory->product(
            $factory->category(),
            $factory->taxRule(),
            $factory->currency(),
            ['baseQuantity' => 100],
        );

        $cart = $factory->cart($customer);
        $factory->cartItem($cart, $product, overrides: ['price' => '100.000000', 'promoPrice' => '100.000000']);

        $cart
            ->setAddressDeliveryId($factory->cartAddress(null, $country)->getId())
            ->setAddressInvoiceId($factory->cartAddress(null, $country)->getId())
            ->setDeliveryModuleId($deliveryModule->getId())
            ->setPaymentModuleId($paymentModule->getId())
            ->setPostage('0')
            ->setPostageTax('0')
            ->save($this->getPropelConnection());

        return $cart;
    }

    private function consentProvider(): ConsentProvider
    {
        $provider = static::getContainer()->get(ConsentProvider::class);
        self::assertInstanceOf(ConsentProvider::class, $provider);

        return $provider;
    }

    private function listen(string $eventName, callable $listener): void
    {
        $this->dispatcher()->addListener($eventName, $listener, 1000);
        $this->registeredListeners[] = [$eventName, $listener];
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
