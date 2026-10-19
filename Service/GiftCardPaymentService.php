<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\Order;
use TheliaGiftCard\Exception\GiftCardPaymentRefusedException;
use TheliaGiftCard\Exception\GiftCardReleaseRefusedException;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\Map\GiftCardOrderTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

/**
 * Spends the gift cards put in a cart for the part of an order the core asks the module to settle,
 * and gives it back.
 *
 * Both are called by the core inside its own transaction, under its locks (and by the cancellation
 * listener, which opens one when none is open): this class neither begins, commits nor rolls back
 * anything. A refusal is an exception, and whoever owns the transaction rolls it back.
 *
 * A card may have been disabled, may have expired or may have been spent elsewhere since it was put
 * in the cart: each debit is one conditional UPDATE that only passes on an enabled, unexpired card
 * with enough credit left, so that two orders cannot spend the same credit. One card refused refuses
 * the part, and the core takes back what it settled before.
 */
final readonly class GiftCardPaymentService
{
    /**
     * "Record has changed since last read in table": MariaDB 11.6.2 and later, snapshot isolation.
     */
    private const SNAPSHOT_CONFLICT_ERROR = 1020;

    /**
     * Values of gift_card_order.status: the row says what the order spent from a card, or that it was
     * given back to the card. Every reader of the table that means "what the order spent" filters on 0.
     */
    public const STATUS_SPENT = 0;
    public const STATUS_GIVEN_BACK = 1;

    /**
     * Spends the amount of the part from the cards of the cart the order comes from, in the order
     * they were put on it, each for what was put on it at most. The part is spent whole or not at
     * all: the cards put on the cart no longer covering it (the buyer changed them after the order
     * was placed) refuse it like a card that cannot pay.
     *
     * @param string $amount decimal string, the amount of the payment part
     *
     * @throws GiftCardPaymentRefusedException when a card cannot pay its share, or when no card is on the cart any more
     */
    public function spend(Order $order, string $amount): void
    {
        $left = GiftCardAmount::cents($amount);

        if ($left <= 0) {
            return;
        }

        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $shares = $this->cardsOnCart($order, $connection);

        // Refused before anything is written when what is on the cart cannot cover the part.
        if ([] === $shares) {
            throw GiftCardPaymentRefusedException::noCardOnTheCart();
        }

        if (array_sum($shares) < $left) {
            throw new GiftCardPaymentRefusedException((int) array_key_last($shares));
        }

        // Locked by primary key in ascending order whatever the order of the debits, so that two
        // orders spending the same cards wait for each other instead of deadlocking.
        $this->lockCards(array_keys($shares), $connection);

        try {
            foreach ($shares as $giftCardId => $applied) {
                if ($left <= 0) {
                    break;
                }

                $share = min($left, $applied);

                if ($share <= 0) {
                    continue;
                }

                $this->debit($order, $giftCardId, GiftCardAmount::decimal($share), $connection);
                $left -= $share;
            }
        } finally {
            GiftCardTableMap::clearInstancePool();
            GiftCardOrderTableMap::clearInstancePool();
        }
    }

    /**
     * Gives back to the cards what the order spent from them, once: a release replayed, or run twice
     * at once, finds nothing left to give back.
     *
     * A row of gift_card_order is never deleted: giving it back marks it (status 1, STATUS_GIVEN_BACK)
     * once the card is credited, so that a replay can tell "already given back" from "nothing seen".
     * Only the rows still marked spent (status 0) are credited.
     *
     * The rows of the order are read, then the cards are locked by primary key in ascending order,
     * then the rows are read again under a lock by primary key: whatever another release took in
     * between is not credited a second time. The first read only says which cards to lock.
     *
     * That first read is a plain one, so it shows what the read view of the transaction shows: the
     * caller (the core, which locks the order before its first read) is the one that keeps that view
     * from being older than the lock of the order. A caller that knows what the order spent, the
     * payment part being released, passes it as $expectedAmount: the rows found under the locks, the
     * ones still spent or, when none is, the ones already given back (a replay, which succeeds and
     * credits nothing), added up in cents by decimal arithmetic, must equal it, or nothing is
     * credited and the release is refused. A row written after the read view was taken is not in the
     * first read, and a release that went on without it would credit less than was spent without a
     * word; no row seen at all is refused the same way.
     *
     * @param string|null $expectedAmount decimal string, what the order spent from cards according to the caller
     *
     * @throws GiftCardReleaseRefusedException when the rows do not add up to $expectedAmount, or when
     *                                         the database refuses the locking reads for a snapshot conflict (1020)
     */
    public function release(int $orderId, ?string $expectedAmount = null, ?ConnectionInterface $connection = null): void
    {
        $connection ??= Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);

        try {
            $this->giveBack($orderId, $expectedAmount, $connection);
        } catch (\Throwable $throwable) {
            throw $this->isSnapshotConflict($throwable) ? GiftCardReleaseRefusedException::snapshotConflict($orderId, $throwable) : $throwable;
        } finally {
            GiftCardTableMap::clearInstancePool();
            GiftCardOrderTableMap::clearInstancePool();
        }
    }

    private function giveBack(int $orderId, ?string $expectedAmount, ConnectionInterface $connection): void
    {
        $found = $connection->prepare('SELECT `id`, `gift_card_id` FROM `gift_card_order` WHERE `order_id` = ?');
        $found->execute([$orderId]);
        $rows = $found->fetchAll(\PDO::FETCH_ASSOC);

        if ([] === $rows) {
            $this->assertSpentIs($orderId, $expectedAmount, 0);

            return;
        }

        $this->lockCards(array_map(static fn (array $row): int => (int) $row['gift_card_id'], $rows), $connection);

        $rowIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        sort($rowIds);
        $locked = $connection->prepare(
            'SELECT `id`, `gift_card_id`, `spend_amount`, `status` FROM `gift_card_order` WHERE `id` IN ('.$this->placeholders($rowIds).') ORDER BY `id` FOR UPDATE'
        );
        $locked->execute($rowIds);
        $lockedRows = $locked->fetchAll(\PDO::FETCH_ASSOC);

        $spentRows = array_values(array_filter($lockedRows, static fn (array $row): bool => self::STATUS_SPENT === (int) $row['status']));
        $givenBackRows = array_values(array_filter($lockedRows, static fn (array $row): bool => self::STATUS_SPENT !== (int) $row['status']));
        $cents = static fn (array $selected): int => array_sum(array_map(
            static fn (array $row): int => GiftCardAmount::exactCents((string) ($row['spend_amount'] ?? '0')),
            $selected,
        ));

        $this->assertSpentIs($orderId, $expectedAmount, [] !== $spentRows ? $cents($spentRows) : $cents($givenBackRows));

        foreach ($spentRows as $row) {
            $credit = $connection->prepare(
                'UPDATE `gift_card` SET `spend_amount` = COALESCE(`spend_amount`, 0) - :amount, `updated_at` = NOW() WHERE `id` = :id'
            );
            $credit->execute(['amount' => (string) ($row['spend_amount'] ?? '0'), 'id' => (int) $row['gift_card_id']]);

            $connection->prepare('UPDATE `gift_card_order` SET `status` = '.self::STATUS_GIVEN_BACK.', `updated_at` = NOW() WHERE `id` = ?')->execute([(int) $row['id']]);
        }
    }

    /**
     * @throws GiftCardReleaseRefusedException when the caller said what was spent and the rows say otherwise
     */
    private function assertSpentIs(int $orderId, ?string $expectedAmount, int $foundCents): void
    {
        if (null === $expectedAmount) {
            return;
        }

        $expectedCents = GiftCardAmount::exactCents($expectedAmount);

        if ($expectedCents !== $foundCents) {
            throw GiftCardReleaseRefusedException::amountMismatch($orderId, GiftCardAmount::decimal($expectedCents), GiftCardAmount::decimal($foundCents));
        }
    }

    private function isSnapshotConflict(\Throwable $throwable): bool
    {
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && self::SNAPSHOT_CONFLICT_ERROR === ($cause->errorInfo[1] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int> cents put on the cart, by gift card id, in the order they were put on it
     */
    private function cardsOnCart(Order $order, ConnectionInterface $connection): array
    {
        $cartId = $order->getCartId();

        if (null === $cartId) {
            return [];
        }

        $statement = $connection->prepare('SELECT `gift_card_id`, `spend_amount` FROM `gift_card_cart` WHERE `cart_id` = ? ORDER BY `id`');
        $statement->execute([$cartId]);

        $shares = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $giftCardId = (int) $row['gift_card_id'];
            $shares[$giftCardId] = ($shares[$giftCardId] ?? 0) + GiftCardAmount::cents($row['spend_amount']);
        }

        return $shares;
    }

    /**
     * @param list<int> $giftCardIds
     */
    private function lockCards(array $giftCardIds, ConnectionInterface $connection): void
    {
        $giftCardIds = array_values(array_unique($giftCardIds));
        sort($giftCardIds);

        if ([] === $giftCardIds) {
            return;
        }

        $statement = $connection->prepare('SELECT `id` FROM `gift_card` WHERE `id` IN ('.$this->placeholders($giftCardIds).') ORDER BY `id` FOR UPDATE');
        $statement->execute($giftCardIds);
        $statement->fetchAll();
    }

    /**
     * @param list<int> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, \count($values), '?'));
    }

    private function debit(Order $order, int $giftCardId, string $amount, ConnectionInterface $connection): void
    {
        $statement = $connection->prepare(
            'UPDATE `gift_card`
             SET `spend_amount` = COALESCE(`spend_amount`, 0) + :amount, `updated_at` = NOW()
             WHERE `id` = :id AND `status` = 1 AND `expiration_date` >= :today
               AND COALESCE(`spend_amount`, 0) + :amount_to_check <= `amount`'
        );
        $statement->execute([
            'amount' => $amount,
            'amount_to_check' => $amount,
            'id' => $giftCardId,
            'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);

        if (1 !== $statement->rowCount()) {
            throw new GiftCardPaymentRefusedException($giftCardId);
        }

        (new GiftCardOrder())
            ->setGiftCardId($giftCardId)
            ->setOrderId($order->getId())
            ->setSpendAmount($amount)
            ->setStatus(self::STATUS_SPENT)
            ->setInitialPostage((string) ($order->getPostage() ?? '0'))
            ->save($connection);
    }
}
