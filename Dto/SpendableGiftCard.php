<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Dto;

/**
 * A card the customer may spend on the cart, without its code: the customer picks it by id.
 * Amounts are decimal strings with two digits.
 */
final readonly class SpendableGiftCard
{
    public function __construct(
        public int $id,
        public string $amount,
        public string $remainingAmount,
        public string $amountOnCart,
        public ?\DateTimeImmutable $expirationDate,
    ) {
    }
}
