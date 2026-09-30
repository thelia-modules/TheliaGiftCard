<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\Order;
use TheliaGiftCard\Exception\GiftCardPaymentRefusedException;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

/**
 * Debits the gift cards put in a cart when its order is paid.
 *
 * A card may have been disabled, may have expired or may have been spent elsewhere since it was
 * put in the cart: each debit is one conditional UPDATE that only passes on an enabled, unexpired
 * card with enough credit left, so that two orders cannot spend the same credit. One card refused
 * refuses the payment, and nothing is debited from the others.
 */
final readonly class GiftCardPaymentService
{
    /**
     * @throws GiftCardPaymentRefusedException
     */
    public function debitCart(Order $order, int $cartId): void
    {
        $cartGiftCards = GiftCardCartQuery::create()->filterByCartId($cartId)->find();

        if (0 === \count($cartGiftCards)) {
            return;
        }

        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            /** @var GiftCardCart $cartGiftCard */
            foreach ($cartGiftCards as $cartGiftCard) {
                $this->debit($order, $cartGiftCard, $connection);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function debit(Order $order, GiftCardCart $cartGiftCard, ConnectionInterface $connection): void
    {
        $giftCardId = (int) $cartGiftCard->getGiftCardId();
        $amount = (string) ($cartGiftCard->getSpendAmount() ?? '0');

        if (bccomp($amount, '0', 6) <= 0) {
            return;
        }

        $this->cancelPreviousAttempt($order, $giftCardId, $connection);

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
            throw new GiftCardPaymentRefusedException(\sprintf('Gift card %d cannot pay %s: disabled, expired or not enough credit left.', $giftCardId, $amount));
        }

        (new GiftCardOrder())
            ->setGiftCardId($giftCardId)
            ->setOrderId($order->getId())
            ->setSpendAmount($amount)
            ->setInitialPostage((string) ($order->getPostage() ?? '0'))
            ->save($connection);
    }

    /**
     * Paying the same order again replaces what the previous attempt took from the card.
     */
    private function cancelPreviousAttempt(Order $order, int $giftCardId, ConnectionInterface $connection): void
    {
        $previous = GiftCardOrderQuery::create()
            ->filterByOrderId($order->getId())
            ->filterByGiftCardId($giftCardId)
            ->findOne($connection);

        if (null === $previous) {
            return;
        }

        $statement = $connection->prepare('UPDATE `gift_card` SET `spend_amount` = `spend_amount` - :amount WHERE `id` = :id');
        $statement->execute(['amount' => (string) ($previous->getSpendAmount() ?? '0'), 'id' => $giftCardId]);
        $previous->delete($connection);
    }
}
