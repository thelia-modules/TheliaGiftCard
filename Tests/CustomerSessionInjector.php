<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use Thelia\Model\Customer;

/**
 * Puts a customer and a cart in the Thelia session on every request the test client sends, the way
 * a front-office login does: front pages read the session, not a firewall token.
 */
final class CustomerSessionInjector implements EventSubscriberInterface
{
    private ?Customer $customer = null;

    private ?Cart $cart = null;

    public function sessionOf(Customer $customer, Cart $cart): void
    {
        $this->customer = $customer;
        $this->cart = $cart;
    }

    public function clear(): void
    {
        $this->customer = null;
        $this->cart = null;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (null === $this->customer) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession(true)) {
            return;
        }

        $session = $request->getSession();

        if (!$session->isStarted()) {
            $session->start();
        }

        $session->set('thelia.customer_user', $this->customer);

        if (null !== $this->cart) {
            $session->set(Session::SESSION_CART_ID_NAME, $this->cart->getId());
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 200],
        ];
    }
}
