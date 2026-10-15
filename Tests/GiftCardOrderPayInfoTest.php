<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use TheliaGiftCard\EventListener\OrderPayListener;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Service\GiftCardGenerateService;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\Service\GiftCardService;

/**
 * The recipient details typed for a gift card bought, linked to its order line when the order
 * is placed.
 */
final class GiftCardOrderPayInfoTest extends GiftCardTestCase
{
    public function testTheDetailsAreLinkedToTheOrderLineOnceTheSessionCartIsAlreadyANewOne(): void
    {
        [$order, $cart, $orderProduct, $cartItemId] = $this->orderOfAGiftCard();
        $details = $this->details($cart->getId(), $cartItemId);
        $backOfficeDetails = $this->backOfficeDetails();

        // An order paid as it is placed (gift cards covering the total): the session no
        // longer hands its cart back, but a new one, not saved yet.
        $this->sessionCartIsANewOne();

        $this->listener()->onOrderPayGiftCardHandleInfo($this->placed($order));

        self::assertSame($orderProduct->getId(), GiftCardInfoCartQuery::create()->findPk($details->getId())?->getOrderProductId());
        self::assertNull(GiftCardInfoCartQuery::create()->findPk($backOfficeDetails->getId())?->getOrderProductId());
    }

    /**
     * @return array{Order, Cart, OrderProduct, int}
     */
    private function orderOfAGiftCard(): array
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order($fixtures->customer($fixtures->customerTitle()));
        $product = $this->giftCardProduct(50.0);
        $orderProduct = $this->orderLine($order, $product, 1, 50.0);
        $cart = CartQuery::create()->findPk($order->getCartId());
        self::assertInstanceOf(Cart::class, $cart);
        $cartItem = $fixtures->cartItem($cart, $product, null, ['price' => '50.000000', 'promoPrice' => '50.000000']);

        return [$order, $cart, $orderProduct, $cartItem->getId()];
    }

    private function details(int $cartId, int $cartItemId): GiftCardInfoCart
    {
        $details = (new GiftCardInfoCart())
            ->setCartId($cartId)
            ->setCartItemId($cartItemId)
            ->setSponsorName('Alice')
            ->setBeneficiaryName('Bob');
        $details->save();

        return $details;
    }

    /**
     * The details of a card created from the back office belong to no cart.
     */
    private function backOfficeDetails(): GiftCardInfoCart
    {
        $details = (new GiftCardInfoCart())
            ->setGiftCardId($this->giftCard()->getId())
            ->setSponsorName('Shop')
            ->setBeneficiaryName('Carol');
        $details->save();

        return $details;
    }

    private function sessionCartIsANewOne(): void
    {
        $session = $this->requestStack()->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setSessionCart(new Cart());
    }

    private function placed(Order $order): OrderEvent
    {
        $event = new OrderEvent(new Order());
        $event->setPlacedOrder($order);

        return $event;
    }

    private function listener(): OrderPayListener
    {
        $container = static::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new OrderPayListener(
            $this->requestStack(),
            $container->get(GiftCardService::class),
            $container->get(GiftCardGenerateService::class),
            $dispatcher,
            new GiftCardPaymentService(),
        );
    }
}
