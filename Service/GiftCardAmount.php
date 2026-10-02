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

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
