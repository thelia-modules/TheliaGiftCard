<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Random\Engine\Secure;
use Random\Randomizer;
use TheliaGiftCard\Exception\GiftCardCodeGenerationException;
use TheliaGiftCard\Model\GiftCardQuery;

/**
 * Draws gift card codes from the cryptographically secure engine of PHP: a code is a bearer
 * credential, a predictable draw lets anyone spend somebody else's card.
 *
 * The draw is repeated while the code is already taken, and the unique index on
 * gift_card.code backs the loop up against two concurrent draws.
 */
final readonly class GiftCardCodeGenerator
{
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

    public const CODE_LENGTH = 8;

    public const MAX_ATTEMPTS = 20;

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $code = $this->randomizer->getBytesFromString(self::ALPHABET, self::CODE_LENGTH);

            if (!GiftCardQuery::create()->filterByCode($code)->exists()) {
                return $code;
            }
        }

        throw new GiftCardCodeGenerationException(\sprintf('No free gift card code found in %d draws.', self::MAX_ATTEMPTS));
    }
}
