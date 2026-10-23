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
    public function __construct(public readonly int $giftCardId, ?\Throwable $previous = null, string $message = 'One of your gift cards can no longer pay its share of this order. Please check your gift cards.')
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The cart holds no card (the buyer took them off after the order was placed): there is no card to
     * blame, the id is 0.
     */
    public static function noCardOnTheCart(): self
    {
        return new self(0, null, 'No gift card is put on your cart any more, so none can pay this order. Please check your gift cards.');
    }
}
