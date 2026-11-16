<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Dto;

use TheliaGiftCard\Model\GiftCard;

/**
 * A card created from an order, with the order line it was bought on: the details typed at the
 * purchase (recipient) belong to the line, not to one card of it.
 */
final readonly class CreatedGiftCard
{
    public function __construct(
        public GiftCard $giftCard,
        public int $orderProductId,
    ) {
    }
}
