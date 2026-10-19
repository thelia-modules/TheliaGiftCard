<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

/**
 * Amounts compared in cents.
 *
 * Totals come from the cart and the order as floats, amounts from the gift card tables as
 * DECIMAL strings: comparing them as they come made a total of 33.33 and two cards of 11.11
 * and 22.22 differ (the previous releases compared their string forms).
 */
final class GiftCardAmount
{
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /**
     * Cents of a decimal string, by decimal arithmetic (no float), rounded half up to the cent:
     * what two sums of DECIMAL columns are compared with, where a float could differ by a cent.
     *
     * @throws \InvalidArgumentException on a negative amount: none is spent, given back or asked
     */
    public static function exactCents(string $amount): int
    {
        $amount = '' === trim($amount) ? '0' : trim($amount);

        if (str_starts_with($amount, '-')) {
            throw new \InvalidArgumentException(\sprintf('A negative amount (%s) is not an amount of money to add up.', $amount));
        }

        return (int) bcmul(bcadd($amount, '0.005', 2), '100', 0);
    }

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * What the cards pay of a total, in cents: what was put on them, never more than the total.
     *
     * The one rule the payment (PaymentListener, on the order total) and the checkout summary
     * (GiftCardThemeHook, on the cart total) both apply, so that the amount left to pay the
     * customer reads is the amount the payment module asks for.
     */
    public static function paidByCards(int $totalCents, int $onCardsCents): int
    {
        return max(0, min($totalCents, $onCardsCents));
    }

    /**
     * What is left to pay of a total once the cards paid their share, in cents.
     */
    public static function leftToPay(int $totalCents, int $onCardsCents): int
    {
        return $totalCents - self::paidByCards($totalCents, $onCardsCents);
    }
}
