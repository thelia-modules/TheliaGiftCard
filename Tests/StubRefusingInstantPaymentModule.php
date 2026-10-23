<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\Cart;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Module\InstantPaymentModuleInterface;

/**
 * Another instant means, settled after the gift cards, that refuses its part: what the cards spent
 * before it must be given back.
 */
final class StubRefusingInstantPaymentModule extends AbstractPaymentModule implements InstantPaymentModuleInterface
{
    public static string $applied = '0';

    public static function reset(): void
    {
        self::$applied = '0';
    }

    public function getAmountAppliedToCart(Cart $cart): string
    {
        return self::$applied;
    }

    public function settlePart(OrderPaymentPart $part): void
    {
        throw new \RuntimeException('Refused.');
    }

    public function releasePart(OrderPaymentPart $part): void
    {
    }

    public function pay(Order $order): ?Response
    {
        return null;
    }

    public function isValidPayment(): bool
    {
        return true;
    }
}
