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
    /**
     * The `code` of the refusal of a code typed by the buyer: the one the buyer is told is wrong.
     */
    public const int CODE_NOT_USABLE = 1;

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

    /**
     * Whatever is wrong with a code typed at the payment step, the buyer is told the same thing:
     * a precise reason would tell someone guessing codes which ones exist.
     */
    public static function codeNotUsable(): self
    {
        return new self('This code cannot be used on this order.', self::CODE_NOT_USABLE);
    }
}
