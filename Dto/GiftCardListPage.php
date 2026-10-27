<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Dto;

use TheliaGiftCard\Model\GiftCard;

/**
 * One page of the back-office card list: the cards of the page, and where the page stands among the
 * pages the criteria select.
 */
final readonly class GiftCardListPage
{
    /**
     * @param list<GiftCard> $cards
     */
    public function __construct(
        public array $cards,
        public int $total,
        public int $page,
        public int $lastPage,
    ) {
    }
}
