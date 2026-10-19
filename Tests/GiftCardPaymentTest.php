<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Checkout\Exception\InvalidPaymentException;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\Order;
use TheliaGiftCard\Exception\GiftCardPaymentRefusedException;
use TheliaGiftCard\Exception\GiftCardReleaseRefusedException;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardAmount;
use TheliaGiftCard\Service\GiftCardPaymentService;

/**
 * The cards put in a cart, spent when the core settles the part of the order they pay.
 */
final class GiftCardPaymentTest extends GiftCardTestCase
{
    public function testPayingTheOrderDebitsTheCardAndRecordsTheUse(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '25');

        (new GiftCardPaymentService())->spend($order, '25');

        self::assertSame(35.0, $this->spentOn($giftCard));
        self::assertSame('25.000000', GiftCardOrderQuery::create()->filterByOrderId($order->getId())->findOne()?->getSpendAmount());
    }

    public function testThePartIsSpentForItsAmountAtMostWhateverIsOnTheCart(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '40');

        (new GiftCardPaymentService())->spend($order, '12.50');

        self::assertSame(12.5, $this->spentOn($giftCard));
        self::assertSame('12.500000', GiftCardOrderQuery::create()->filterByOrderId($order->getId())->findOne()?->getSpendAmount());
    }

    public function testThePartIsSpentFromTheCardsInTheOrderTheyWerePutOnTheCart(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $first = $this->giftCard(['amount' => '80']);
        $second = $this->giftCard(['amount' => '80']);
        $this->putInCart($cart, $first, '80');
        $this->putInCart($cart, $second, '40');

        (new GiftCardPaymentService())->spend($order, '100');

        self::assertSame([80.0, 20.0], [$this->spentOn($first), $this->spentOn($second)]);
        self::assertSame(2, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testCardsThatNoLongerCoverThePartRefuseItAndSpendNothing(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '20');

        $this->assertRefused($order, '30');

        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testAZeroPartSpendsNothing(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '20');

        (new GiftCardPaymentService())->spend($order, '0.000000');

        self::assertSame(0.0, $this->spentOn($giftCard));
    }

    public function testACardDisabledSinceItWasPutInTheCartCannotPay(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '20');
        $giftCard->setStatus(0)->save();

        $this->assertRefused($order, '20');

        self::assertSame(0.0, $this->spentOn($giftCard));
        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testAnExpiredCardCannotPay(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'expirationDate' => new \DateTime('yesterday')]);
        $this->putInCart($cart, $giftCard, '20');

        $this->assertRefused($order, '20');

        self::assertSame(0.0, $this->spentOn($giftCard));
    }

    public function testACardCannotPayMoreThanWhatIsLeftOnIt(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '40']);
        $this->putInCart($cart, $giftCard, '10.01');

        $this->assertRefused($order, '20');

        self::assertSame(40.0, $this->spentOn($giftCard));
    }

    public function testSpendingThePartDebitsTheCardsOfTheCartOfTheOrderNotOfTheSession(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '15');
        $sessionCart = $this->createFixtureFactory()->cart($order->getCustomer());
        $sessionCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($sessionCart, $sessionCard, '40');
        $session = $this->requestStack()->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($order->getCustomer());
        $session->setSessionCart($sessionCart);

        (new GiftCardPaymentService())->spend($order, '15');

        self::assertSame(15.0, $this->spentOn($giftCard));
        self::assertSame(0.0, $this->spentOn($sessionCard));
    }

    public function testACartWithoutAnyCardRefusesThePartWithAMessageThatSaysSo(): void
    {
        [$order] = $this->orderAndCart();

        try {
            (new GiftCardPaymentService())->spend($order, '10');
            self::fail('The payment must be refused.');
        } catch (GiftCardPaymentRefusedException $refusal) {
            self::assertSame(0, $refusal->giftCardId);
            self::assertStringContainsString('No gift card is put on your cart', $refusal->getMessage());
        }

        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testTheReleaseGivesBackWhatTheOrderSpentWhenItAddsUpToThePartAndNoMoreThanOnce(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '25');
        $service = new GiftCardPaymentService();
        $service->spend($order, '25');
        self::assertSame(35.0, $this->spentOn($giftCard));

        $service->release((int) $order->getId(), '25.000000');

        self::assertSame(10.0, $this->spentOn($giftCard));
        self::assertSame(0, $this->rowsOf($order, GiftCardPaymentService::STATUS_SPENT));
        self::assertSame(1, $this->rowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK), 'The row stays, marked given back.');

        // A replay, with or without the amount to check (the listener of the cancellation), succeeds and
        // credits nothing: the row given back is never credited again.
        $service->release((int) $order->getId(), '25.000000');
        $service->release((int) $order->getId());
        self::assertSame(10.0, $this->spentOn($giftCard));
        self::assertSame(1, $this->rowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK));
    }

    public function testAReplayOfAPartWhoseAmountIsNotWhatWasGivenBackIsRefused(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '25');
        $service = new GiftCardPaymentService();
        $service->spend($order, '25');
        $service->release((int) $order->getId(), '25.000000');

        try {
            $service->release((int) $order->getId(), '30.000000');
            self::fail('A replay for another amount than the one given back must be refused.');
        } catch (GiftCardReleaseRefusedException) {
        }

        self::assertSame(10.0, $this->spentOn($giftCard));
    }

    public function testAGivenBackRowIsNeverCreditedAgainByARowStillSpentOfTheSameOrder(): void
    {
        // One row given back, one still spent: only the second is credited.
        [$order, $cart] = $this->orderAndCart();
        $first = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $second = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $first, '25');
        $service = new GiftCardPaymentService();
        $service->spend($order, '25');
        $service->release((int) $order->getId());
        self::assertSame(10.0, $this->spentOn($first));
        (new GiftCardOrder())->setGiftCardId($second->getId())->setOrderId($order->getId())->setSpendAmount('7')->setInitialPostage('0')->save();
        $second->setSpendAmount('17')->save();

        $service->release((int) $order->getId());

        self::assertSame(10.0, $this->spentOn($first), 'The row given back is not credited again.');
        self::assertSame(10.0, $this->spentOn($second), 'The row still spent is.');
    }

    public function testNegativeAmountsAreNotAddedUp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GiftCardAmount::exactCents('-1.00');
    }

    public function testTheReleaseOfAPartTheRowsDoNotAddUpToCreditsNothing(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '25');
        $service = new GiftCardPaymentService();
        $service->spend($order, '25');

        try {
            $service->release((int) $order->getId(), '30.000000');
            self::fail('A release that would credit less than the part must be refused.');
        } catch (GiftCardReleaseRefusedException $refusal) {
            self::assertStringContainsString('spent 25.00', $refusal->getMessage());
            self::assertStringContainsString('part to give back is 30.00', $refusal->getMessage());
        }

        self::assertSame(35.0, $this->spentOn($giftCard), 'Nothing is credited on a refusal.');
        self::assertSame(1, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testTheReleaseOfAPartWithNoRowAtAllIsRefusedToo(): void
    {
        [$order] = $this->orderAndCart();

        $this->expectException(GiftCardReleaseRefusedException::class);

        (new GiftCardPaymentService())->release((int) $order->getId(), '25.000000');
    }

    public function testTheRowsAreAddedUpInCentsByDecimalArithmetic(): void
    {
        // 0.1 + 0.2 is not 0.3 for a float: a sum compared as floats would refuse this part.
        [$order, $cart] = $this->orderAndCart();
        $first = $this->giftCard(['amount' => '50']);
        $second = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $first, '0.10');
        $this->putInCart($cart, $second, '0.20');
        $service = new GiftCardPaymentService();
        $service->spend($order, '0.30');

        $service->release((int) $order->getId(), '0.300000');

        self::assertSame([0.0, 0.0], [$this->spentOn($first), $this->spentOn($second)]);
    }

    public function testADatabaseSnapshotConflictOnTheReleaseIsAClearRefusalAndAnyOtherErrorPassesThrough(): void
    {
        // Error 1020 only exists on MariaDB 11.6.2 and later with snapshot isolation: simulated here.
        $conflict = new \PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'gift_card\'');
        $conflict->errorInfo = ['HY000', 1020, 'Record has changed since last read in table \'gift_card\''];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('prepare')->willThrowException($conflict);

        try {
            (new GiftCardPaymentService())->release(42, null, $connection);
            self::fail('The conflict must be refused.');
        } catch (GiftCardReleaseRefusedException $refusal) {
            self::assertStringContainsString('order 42', $refusal->getMessage());
            self::assertSame($conflict, $refusal->getPrevious());
        }

        $other = new \PDOException('Deadlock');
        $other->errorInfo = ['40001', 1213, 'Deadlock found'];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('prepare')->willThrowException($other);

        try {
            (new GiftCardPaymentService())->release(42, null, $connection);
            self::fail('The deadlock must pass through.');
        } catch (\PDOException $thrown) {
            self::assertSame($other, $thrown);
        }
    }

    private function rowsOf(Order $order, int $status): int
    {
        return GiftCardOrderQuery::create()->filterByOrderId($order->getId())->filterByStatus($status)->count();
    }

    private function assertRefused(Order $order, string $amount): void
    {
        try {
            (new GiftCardPaymentService())->spend($order, $amount);
            self::fail('The payment must be refused.');
        } catch (GiftCardPaymentRefusedException $refusal) {
            // The checkout answers a payment refusal by sending the buyer back to the payment step.
            self::assertInstanceOf(InvalidPaymentException::class, $refusal);
        }
    }

    /**
     * @return array{Order, Cart}
     */
    private function orderAndCart(): array
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle());
        $order = $fixtures->order($customer);
        // The order total caps the debit: one line of 100.
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(['isDefault' => false]), $fixtures->currency(), ['basePrice' => 100.0]);
        $this->orderLine($order, $product, 1, 100.0);
        $cart = CartQuery::create()->findPk($order->getCartId());
        self::assertInstanceOf(Cart::class, $cart);

        return [$order, $cart];
    }

    private function putInCart(Cart $cart, GiftCard $giftCard, string $amount): void
    {
        (new GiftCardCart())->setCartId($cart->getId())->setGiftCardId($giftCard->getId())->setSpendAmount($amount)->save();
    }

    private function spentOn(GiftCard $giftCard): float
    {
        return (float) GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount();
    }
}
