<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\Customer;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Module\PaymentModuleInterface;
use TheliaGiftCard\EventListener\CartListener;
use TheliaGiftCard\EventListener\OrderPayListener;
use TheliaGiftCard\EventListener\PaymentListener;
use TheliaGiftCard\Exception\GiftCardSpendRefusedException;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardCartSpending;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\Service\GiftCardService;

/**
 * Gift cards put on the cart at the payment step, deducted from what the payment module asks
 * for, and debited when the order is placed.
 */
final class GiftCardCartSpendingTest extends GiftCardTestCase
{
    public function testACardPaysPartOfTheCart(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);

        $spent = $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30');

        self::assertSame(3000, $spent);
        self::assertSame(3000, $this->spending()->amountOnCart($cart));
        self::assertFalse($this->spending()->coversTheCart($cart));
    }

    public function testTwoCardsNeverSpendMoreThanTheAmountAskedFor(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $first = $this->giftCard(['amount' => '20', 'beneficiaryCustomerId' => $customer->getId()]);
        $second = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);

        $spent = $this->spending()->spend($customer, $cart, [$first->getId(), $second->getId()], '60');

        self::assertSame(6000, $spent);
        self::assertSame(['20.000000', '40.000000'], [$this->onCart($first), $this->onCart($second)]);
    }

    public function testTheCardsNeverSpendMoreThanTheCartWithItsPostage(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $first = $this->giftCard(['amount' => '80', 'beneficiaryCustomerId' => $customer->getId()]);
        $second = $this->giftCard(['amount' => '80', 'beneficiaryCustomerId' => $customer->getId()]);

        $spent = $this->spending()->spend($customer, $cart, [$first->getId(), $second->getId()], '150');

        self::assertSame(10000, $spent);
        self::assertTrue($this->spending()->coversTheCart($cart));
    }

    public function testCardsCoveringATotalWithCentsPayForAllOfIt(): void
    {
        [$customer, $cart] = $this->cartOf(30.0, '3.33');
        $first = $this->giftCard(['amount' => '11.11', 'beneficiaryCustomerId' => $customer->getId()]);
        $second = $this->giftCard(['amount' => '22.22', 'beneficiaryCustomerId' => $customer->getId()]);

        $this->spending()->spend($customer, $cart, [$first->getId(), $second->getId()], '33.33');

        self::assertTrue($this->giftCardService()->isGiftCardPayment($cart));
    }

    public function testCardsCoveringTheCartLeaveTheGiftCardModuleAlone(): void
    {
        [$customer, $cart] = $this->cartOf(40.0, '0');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->spending()->spend($customer, $cart, [$giftCard->getId()], '50');

        $other = new IsValidPaymentEvent($this->paymentModule('Cheque'), $cart);
        $this->paymentListener()->forceGiftCardPayment($other);
        $giftCardModule = new IsValidPaymentEvent($this->paymentModule('TheliaGiftCard'), $cart);
        $this->paymentListener()->forceGiftCardPayment($giftCardModule);

        self::assertTrue($other->isPropagationStopped());
        self::assertFalse($giftCardModule->isPropagationStopped());
    }

    public function testAPartialCardLeavesTheOtherModulesAlone(): void
    {
        [$customer, $cart] = $this->cartOf(40.0, '0');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->spending()->spend($customer, $cart, [$giftCard->getId()], '39.99');

        $other = new IsValidPaymentEvent($this->paymentModule('Cheque'), $cart);
        $this->paymentListener()->forceGiftCardPayment($other);

        self::assertFalse($other->isPropagationStopped());
    }

    public function testACardOfAnotherCustomerCannotBeSpent(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $someoneElse = $this->createFixtureFactory()->customer($this->createFixtureFactory()->customerTitle());
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $someoneElse->getId()]);

        try {
            $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30');
            self::fail('The card of another customer must be refused.');
        } catch (GiftCardSpendRefusedException) {
        }

        self::assertSame(0, $this->spending()->amountOnCart($cart));
    }

    public function testAnAmountThatIsNotANumberIsRefused(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);

        $this->expectException(GiftCardSpendRefusedException::class);

        $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30abc');
    }

    public function testAnAmountOfZeroTakesTheCardsOff(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30');

        self::assertSame(0, $this->spending()->spend($customer, $cart, [$giftCard->getId()], '0'));
        self::assertSame(0, $this->spending()->amountOnCart($cart));
    }

    public function testSpendingCardsTakesTheCouponsOff(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $cleared = 0;
        $listener = static function () use (&$cleared): void {
            ++$cleared;
        };
        $dispatcher = $this->dispatcher();
        $dispatcher->addListener(TheliaEvents::COUPON_CLEAR_ALL, $listener, 255);

        try {
            $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30');
        } finally {
            $dispatcher->removeListener(TheliaEvents::COUPON_CLEAR_ALL, $listener);
        }

        self::assertSame(1, $cleared);
    }

    public function testOnlyTheEnabledUnexpiredCardsOfTheCustomerAreListed(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $spendable = $this->giftCard(['amount' => '50', 'spendAmount' => '20', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId(), 'expirationDate' => new \DateTime('yesterday')]);
        $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId(), 'status' => 0]);
        $this->giftCard(['amount' => '50', 'spendAmount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->spending()->spend($customer, $cart, [$spendable->getId()], '10');

        $cards = $this->spending()->spendableCards($customer, $cart);

        self::assertCount(1, $cards);
        self::assertSame($spendable->getId(), $cards[0]->id);
        self::assertSame(['50.00', '30.00', '10.00'], [$cards[0]->amount, $cards[0]->remainingAmount, $cards[0]->amountOnCart]);
    }

    public function testACartHoldingAGiftCardIsNotPaidWithGiftCards(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $this->createFixtureFactory()->cartItem($cart, $this->giftCardProduct(50), null, ['price' => '50', 'promoPrice' => '50']);
        $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);

        self::assertSame([], $this->spending()->spendableCards($customer, $cart));
    }

    public function testChangingTheQuantityOfALineTakesTheCardsOff(): void
    {
        [$customer, $cart] = $this->cartOf(90.0, '10');
        $giftCard = $this->giftCard(['amount' => '50', 'beneficiaryCustomerId' => $customer->getId()]);
        $this->spending()->spend($customer, $cart, [$giftCard->getId()], '30');

        $listener = static::getContainer()->get(CartListener::class);
        self::assertInstanceOf(CartListener::class, $listener);
        self::assertArrayHasKey(TheliaEvents::CART_UPDATEITEM, CartListener::getSubscribedEvents());
        $listener->resetGiftCard(new CartEvent($cart));

        self::assertSame(0, $this->spending()->amountOnCart($cart));
    }

    public function testThePaymentModuleIsAskedForTheTotalLessTheCardsOfTheOrderCart(): void
    {
        // A cart of 100 € with 30 € of gift card: PayPal reads ORDER_PAY_GET_TOTAL on the order.
        [$order, $cart] = $this->orderOf(100.0);
        $this->putInCart($cart, $this->giftCard(['amount' => '50']), '30');

        self::assertSame(70.0, $this->payTotal($order));
    }

    public function testAnAmountBeyondTheTotalIsDebitedForTheTotalOnly(): void
    {
        [$order, $cart] = $this->orderOf(100.0);
        $first = $this->giftCard(['amount' => '80']);
        $second = $this->giftCard(['amount' => '80']);
        $this->putInCart($cart, $first, '80');
        $this->putInCart($cart, $second, '40');

        // Before the debit, the payment deducts the whole total, not nothing.
        self::assertSame(0.0, $this->payTotal($order));

        (new GiftCardPaymentService())->debitCart($order, (int) $cart->getId());

        self::assertSame([80.0, 20.0], [$this->spentOn($first), $this->spentOn($second)]);
        self::assertSame(0.0, $this->payTotal($order));
    }

    public function testTheCardsAreDebitedBeforeThePaymentModuleIsCalled(): void
    {
        [$order, $cart] = $this->orderOf(100.0);
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '30');

        self::assertSame(['onOrderPayGiftCard', 192], OrderPayListener::getSubscribedEvents()[TheliaEvents::ORDER_BEFORE_PAYMENT]);
        $listener = static::getContainer()->get(OrderPayListener::class);
        self::assertInstanceOf(OrderPayListener::class, $listener);
        $listener->onOrderPayGiftCard(new OrderEvent($order));

        self::assertSame(30.0, $this->spentOn($giftCard));
        self::assertSame(1, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
        self::assertSame(70.0, $this->payTotal($order));
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
            ->setPostage($postage)
            ->save();

        return [$customer, $cart];
    }

    /**
     * @return array{Order, Cart}
     */
    private function orderOf(float $total): array
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order();
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(['isDefault' => false]), $fixtures->currency(), ['basePrice' => $total]);
        $this->orderLine($order, $product, 1, $total);
        $cart = CartQuery::create()->findPk($order->getCartId());
        self::assertInstanceOf(Cart::class, $cart);

        return [$order, $cart];
    }

    private function payTotal(Order $order): float
    {
        $event = new OrderPayTotalEvent($order);
        $event->setTax(0)->setIncludePostage(true)->setIncludeDiscount(true);
        $this->dispatcher()->dispatch($event, TheliaEvents::ORDER_PAY_GET_TOTAL);

        return $event->getTotal();
    }

    private function putInCart(Cart $cart, GiftCard $giftCard, string $amount): void
    {
        (new GiftCardCart())->setCartId($cart->getId())->setGiftCardId($giftCard->getId())->setSpendAmount($amount)->save();
    }

    private function onCart(GiftCard $giftCard): ?string
    {
        return GiftCardCartQuery::create()->filterByGiftCardId($giftCard->getId())->findOne()?->getSpendAmount();
    }

    private function spentOn(GiftCard $giftCard): float
    {
        return (float) GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount();
    }

    private function paymentModule(string $code): PaymentModuleInterface
    {
        $module = ModuleQuery::create()->findOneByCode($code);
        self::assertNotNull($module, \sprintf('The test database has no %s module.', $code));
        $instance = $module->getPaymentModuleInstance(static::getContainer());
        self::assertInstanceOf(PaymentModuleInterface::class, $instance);

        return $instance;
    }

    private function spending(): GiftCardCartSpending
    {
        $spending = static::getContainer()->get(GiftCardCartSpending::class);
        self::assertInstanceOf(GiftCardCartSpending::class, $spending);

        return $spending;
    }

    private function giftCardService(): GiftCardService
    {
        $service = static::getContainer()->get(GiftCardService::class);
        self::assertInstanceOf(GiftCardService::class, $service);

        return $service;
    }

    private function paymentListener(): PaymentListener
    {
        $listener = static::getContainer()->get(PaymentListener::class);
        self::assertInstanceOf(PaymentListener::class, $listener);

        return $listener;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
