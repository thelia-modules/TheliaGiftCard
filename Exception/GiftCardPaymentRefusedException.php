<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Exception;

use Thelia\Domain\Checkout\Exception\InvalidPaymentException;

/**
 * A card of the cart can no longer pay its share. A payment refusal of the checkout (InvalidPaymentException), so the
 * tunnel sends the buyer back to the payment step with this sentence instead of failing with a 500.
 */
final class GiftCardPaymentRefusedException extends InvalidPaymentException
{
    public function __construct(public readonly int $giftCardId, ?\Throwable $previous = null)
    {
        parent::__construct('One of your gift cards can no longer pay its share of this order. Please check your gift cards.', 0, $previous);
    }
}
