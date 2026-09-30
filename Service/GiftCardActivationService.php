<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaGiftCard\Model\GiftCardQuery;

/**
 * Attaches a gift card to the account of the customer who typed its code.
 *
 * Only an enabled, unexpired card nobody holds yet can be attached. The caller gets a plain
 * yes or no, whatever the reason of a refusal: telling a wrong code from a disabled or an
 * already attached one would help someone guessing codes.
 */
final readonly class GiftCardActivationService
{
    public function attachToCustomer(string $code, int $customerId): bool
    {
        $code = trim($code);

        if ('' === $code) {
            return false;
        }

        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
            ->filterByStatus(1)
            ->filterByExpirationDate((new \DateTimeImmutable('today'))->format('Y-m-d'), Criteria::GREATER_EQUAL)
            ->findOne();

        if (null === $giftCard) {
            return false;
        }

        // Conditional update: of two customers typing the same code at the same time, one wins.
        $updatedRows = GiftCardQuery::create()
            ->filterById($giftCard->getId())
            ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
            ->update(['BeneficiaryCustomerId' => $customerId]);

        return 1 === $updatedRows;
    }
}
