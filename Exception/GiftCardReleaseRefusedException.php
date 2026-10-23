<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Exception;

/**
 * What an order spent from gift cards could not be given back: nothing was credited. Thrown, never
 * swallowed: the core logs it, leaves the payment part settled, and a later cancellation of the order
 * asks again.
 */
final class GiftCardReleaseRefusedException extends \RuntimeException
{
    /**
     * The rows of the order read under the locks do not add up to the part being released: another
     * transaction wrote or removed some after the one releasing took its read view, so what would be
     * credited is not what was spent.
     */
    public static function amountMismatch(int $orderId, string $expected, string $found): self
    {
        return new self(\sprintf(
            'The gift cards of order %d spent %s according to the rows read under lock, while the payment part to give back is %s: nothing was credited.',
            $orderId,
            $found,
            $expected,
        ));
    }

    /**
     * MariaDB 11.6.2 and later, with snapshot isolation on (error 1020): a locking read met a row
     * written since the read view of the transaction was taken.
     */
    public static function snapshotConflict(int $orderId, \Throwable $previous): self
    {
        return new self(
            \sprintf('The gift cards of order %d were changed by another transaction since this one read them: nothing was credited, release the order again once that transaction is over.', $orderId),
            0,
            $previous,
        );
    }
}
