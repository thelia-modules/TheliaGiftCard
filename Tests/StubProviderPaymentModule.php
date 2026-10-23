<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Module\BalanceChargingPaymentModuleInterface;

/**
 * A payment provider that charges only the balance the gift cards leave, for the tests that pay
 * the rest of an order with something else than a card.
 */
final class StubProviderPaymentModule extends AbstractPaymentModule implements BalanceChargingPaymentModuleInterface
{
    /**
     * @var list<string> what the provider was asked to charge, by order
     */
    public static array $charged = [];

    public static function reset(): void
    {
        self::$charged = [];
    }

    public function pay(Order $order): ?Response
    {
        self::$charged[] = $this->getAmountToCharge($order);

        return null;
    }

    public function isValidPayment(): bool
    {
        return true;
    }

    public function supportsPaymentRetry(): bool
    {
        return true;
    }
}
