<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use Thelia\Model\Order;
use TheliaGiftCard\EventListener\OrderPayListener;
use TheliaGiftCard\Exception\GiftCardPaymentRefusedException;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardPaymentService;

/**
 * The cards put in a cart, debited when its order is paid.
 */
final class GiftCardPaymentTest extends GiftCardTestCase
{
    public function testPayingTheOrderDebitsTheCardAndRecordsTheUse(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '10']);
        $this->putInCart($cart, $giftCard, '25');

        (new GiftCardPaymentService())->debitCart($order, $cart->getId());

        self::assertSame(35.0, $this->spentOn($giftCard));
        self::assertSame('25.000000', GiftCardOrderQuery::create()->filterByOrderId($order->getId())->findOne()?->getSpendAmount());
    }

    public function testPayingTheSameOrderAgainDoesNotDebitTheCardTwice(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '20');

        (new GiftCardPaymentService())->debitCart($order, $cart->getId());
        (new GiftCardPaymentService())->debitCart($order, $cart->getId());

        self::assertSame(20.0, $this->spentOn($giftCard));
        self::assertSame(1, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testACardDisabledSinceItWasPutInTheCartCannotPay(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '20');
        $giftCard->setStatus(0)->save();

        $this->assertRefused($order, $cart);

        self::assertSame(0.0, $this->spentOn($giftCard));
        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testAnExpiredCardCannotPay(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'expirationDate' => new \DateTime('yesterday')]);
        $this->putInCart($cart, $giftCard, '20');

        $this->assertRefused($order, $cart);

        self::assertSame(0.0, $this->spentOn($giftCard));
    }

    public function testACardCannotPayMoreThanWhatIsLeftOnIt(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '40']);
        $this->putInCart($cart, $giftCard, '10.01');

        $this->assertRefused($order, $cart);

        self::assertSame(40.0, $this->spentOn($giftCard));
    }

    public function testTheOrderPaymentEventDebitsTheCardsOfTheSessionCart(): void
    {
        [$order, $cart] = $this->orderAndCart();
        $giftCard = $this->giftCard(['amount' => '50']);
        $this->putInCart($cart, $giftCard, '15');
        $session = $this->requestStack()->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($order->getCustomer());
        $session->setSessionCart($cart);
        $event = new OrderEvent($order);
        $event->setPlacedOrder($order);

        $listener = static::getContainer()->get(OrderPayListener::class);
        self::assertInstanceOf(OrderPayListener::class, $listener);
        $listener->onOrderPayGiftCard($event);

        self::assertSame(15.0, $this->spentOn($giftCard));
    }

    private function assertRefused(Order $order, Cart $cart): void
    {
        try {
            (new GiftCardPaymentService())->debitCart($order, $cart->getId());
            self::fail('The payment must be refused.');
        } catch (GiftCardPaymentRefusedException) {
            // expected
        }
    }

    /**
     * @return array{Order, Cart}
     */
    private function orderAndCart(): array
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle());

        return [$fixtures->order($customer), $fixtures->cart($customer)];
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
