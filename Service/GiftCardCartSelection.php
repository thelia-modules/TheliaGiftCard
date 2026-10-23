<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Thelia\Model\Cart;
use Thelia\Model\Customer;
use TheliaGiftCard\Exception\GiftCardSpendRefusedException;
use TheliaGiftCard\Exception\GiftCardTooManyCodeAttemptsException;

/**
 * What the buyer does with their gift cards on the payment step: tick a card, untick it, type the
 * code of a card, take every card off.
 *
 * GiftCardCartSpending::spend() takes everything off the cart before it judges what it is asked
 * for, so a request it refuses would also have lost the buyer their cards and coupons. Here the
 * request is checked against the cards the customer can spend first: a refusal changes nothing.
 */
final readonly class GiftCardCartSelection
{
    public function __construct(
        private GiftCardCartSpending $cartSpending,
        private GiftCardActivationService $activation,
        private GiftCardActivationLimiter $limiter,
    ) {
    }

    /**
     * Puts the card on the cart when it is not on it, takes it off when it is.
     *
     * @throws GiftCardSpendRefusedException
     */
    public function toggle(Customer $customer, Cart $cart, int $giftCardId): void
    {
        $onCart = $this->idsOnCart($customer, $cart);

        if (\in_array($giftCardId, $onCart, true)) {
            $this->apply($customer, $cart, array_values(array_diff($onCart, [$giftCardId])));

            return;
        }

        $this->assertSpendable($customer, $cart, $giftCardId);
        $this->apply($customer, $cart, [...$onCart, $giftCardId]);
    }

    /**
     * Puts the card that carries the code on the cart: a card of the customer, or one nobody
     * holds yet, which then becomes theirs (what the account page does with the same code).
     * Every attempt counts against the limits of the activation form: a code is guessed by
     * trying them one after the other.
     *
     * @throws GiftCardTooManyCodeAttemptsException when the limits are reached
     * @throws GiftCardSpendRefusedException        CODE_NOT_USABLE, the same way for a code that is
     *                                              wrong, expired, disabled, spent out or held by
     *                                              someone else; any other code when the cart
     *                                              cannot take a card, whatever the code
     */
    public function applyCode(Customer $customer, Cart $cart, string $code, ?string $clientIp): void
    {
        if (!$this->limiter->allows((int) $customer->getId(), $clientIp)) {
            throw new GiftCardTooManyCodeAttemptsException();
        }

        // Before the code is looked at, so that it says nothing about the code: a card must not
        // be attached to the account of a buyer whose cart cannot take it.
        if ($this->cartSpending->holdsAGiftCard($cart)) {
            throw GiftCardSpendRefusedException::cartHoldsAGiftCard();
        }

        $giftCardId = $this->cartSpending->spendableCardIdOfCustomerByCode($customer, $code);

        if (null === $giftCardId && $this->activation->attachToCustomer($code, (int) $customer->getId())) {
            $giftCardId = $this->cartSpending->spendableCardIdOfCustomerByCode($customer, $code);
        }

        if (null === $giftCardId) {
            throw GiftCardSpendRefusedException::codeNotUsable();
        }

        $onCart = $this->idsOnCart($customer, $cart);

        if (\in_array($giftCardId, $onCart, true)) {
            return;
        }

        $this->assertSpendable($customer, $cart, $giftCardId);
        $this->apply($customer, $cart, [...$onCart, $giftCardId]);
    }

    /**
     * Takes every gift card off the cart. The coupons are left alone.
     */
    public function clear(Cart $cart): void
    {
        $this->cartSpending->reset($cart);
    }

    /**
     * @return list<int> the cards that pay something of the cart now, in the order they were put on it
     */
    private function idsOnCart(Customer $customer, Cart $cart): array
    {
        $ids = [];

        foreach ($this->cartSpending->spendableCards($customer, $cart) as $card) {
            if ('0.00' !== $card->amountOnCart) {
                $ids[] = $card->id;
            }
        }

        return $ids;
    }

    /**
     * @throws GiftCardSpendRefusedException
     */
    private function assertSpendable(Customer $customer, Cart $cart, int $giftCardId): void
    {
        foreach ($this->cartSpending->spendableCards($customer, $cart) as $card) {
            if ($card->id === $giftCardId) {
                return;
            }
        }

        throw GiftCardSpendRefusedException::cardNotSpendable();
    }

    /**
     * @param list<int> $giftCardIds
     */
    private function apply(Customer $customer, Cart $cart, array $giftCardIds): void
    {
        if ([] === $giftCardIds) {
            $this->cartSpending->reset($cart);

            return;
        }

        $this->cartSpending->spendAsMuchAsTheCartCosts($customer, $cart, $giftCardIds);
    }
}
