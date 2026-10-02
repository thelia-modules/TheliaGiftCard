<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Exception;

/**
 * A request to spend gift cards on a cart that cannot be honoured. The message never holds a
 * card code.
 */
final class GiftCardSpendRefusedException extends \RuntimeException
{
    public static function invalidAmount(): self
    {
        return new self('The amount to spend is not a number.');
    }

    public static function cartHoldsAGiftCard(): self
    {
        return new self('A cart that holds a gift card cannot be paid with gift cards.');
    }

    public static function cardNotSpendable(): self
    {
        return new self('One of the selected gift cards cannot be spent by this customer.');
    }
}
