<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Cart;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Country;
use Thelia\Model\Customer;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\State;
use TheliaGiftCard\Dto\SpendableGiftCard;
use TheliaGiftCard\Exception\GiftCardSpendRefusedException;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The gift cards a customer puts on their cart before paying.
 *
 * Everything is read from the cart itself — its delivery address, its postage, its discount —
 * never from the order kept in session: the Thelia 3 checkout stores the buyer's choices on
 * the cart, and a cart is what is about to be paid.
 *
 * Only the cards the customer is the beneficiary of, enabled, unexpired and not spent out,
 * can be put on the cart. What is put on it never goes beyond the amount the customer asked
 * for, nor beyond what the cart costs, postage included.
 */
final readonly class GiftCardCartSpending
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return list<SpendableGiftCard>
     */
    public function spendableCards(Customer $customer, Cart $cart): array
    {
        if ($this->holdsAGiftCard($cart)) {
            return [];
        }

        $amountsOnCart = $this->amountsOnCart((int) $cart->getId());
        $cards = [];

        /** @var GiftCard $giftCard */
        foreach ($this->spendableQuery($customer)->orderById()->find() as $giftCard) {
            $expirationDate = $giftCard->getExpirationDate();

            $cards[] = new SpendableGiftCard(
                (int) $giftCard->getId(),
                GiftCardAmount::decimal(GiftCardAmount::cents($giftCard->getAmount())),
                GiftCardAmount::decimal($this->remainingOn($giftCard)),
                GiftCardAmount::decimal($amountsOnCart[(int) $giftCard->getId()] ?? 0),
                $expirationDate instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($expirationDate) : null,
            );
        }

        return $cards;
    }

    /**
     * Puts the selected cards on the cart, in the order given, for the amount asked for at most.
     *
     * Whatever was put on the cart before is taken off, and so are the coupons: a cart is paid
     * either with coupons or with gift cards. An amount of zero or less only takes them off.
     *
     * @param list<int> $giftCardIds
     *
     * @return int the amount now put on the cart, in cents
     *
     * @throws GiftCardSpendRefusedException
     */
    public function spend(Customer $customer, Cart $cart, array $giftCardIds, string $amount): int
    {
        $amount = trim($amount);

        if (!is_numeric($amount) || !is_finite((float) $amount)) {
            throw GiftCardSpendRefusedException::invalidAmount();
        }

        $this->dispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::COUPON_CLEAR_ALL);
        $this->reset($cart);

        $requested = GiftCardAmount::cents($amount);
        $giftCardIds = array_values(array_unique(array_map('intval', $giftCardIds)));

        if ($requested <= 0 || [] === $giftCardIds) {
            return 0;
        }

        if ($this->holdsAGiftCard($cart)) {
            throw GiftCardSpendRefusedException::cartHoldsAGiftCard();
        }

        $giftCards = [];
        /** @var GiftCard $giftCard */
        foreach ($this->spendableQuery($customer)->filterById($giftCardIds, Criteria::IN)->find() as $giftCard) {
            $giftCards[(int) $giftCard->getId()] = $giftCard;
        }

        if (\count($giftCards) !== \count($giftCardIds)) {
            throw GiftCardSpendRefusedException::cardNotSpendable();
        }

        // The coupons just taken off changed the discount the cart holds.
        $cart->reload();
        $left = min($requested, $this->cartTotal($cart));
        $spent = 0;

        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            foreach ($giftCardIds as $giftCardId) {
                if ($left <= 0) {
                    break;
                }

                $share = min($left, $this->remainingOn($giftCards[$giftCardId]));
                $this->putOnCart($giftCards[$giftCardId], (int) $cart->getId(), $share);
                $left -= $share;
                $spent += $share;
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $spent;
    }

    /**
     * Puts one card on the cart, for the amount asked for at most, without taking the others
     * off. Kept for GiftCardSpend, the entry point of the previous releases.
     *
     * @return int the amount put on the cart, in cents
     */
    public function spendOne(GiftCard $giftCard, Cart $cart, int $requested): int
    {
        $alreadyOnCart = $this->amountOnCart($cart) - ($this->amountsOnCart((int) $cart->getId())[(int) $giftCard->getId()] ?? 0);
        $share = max(0, min($requested, $this->remainingOn($giftCard), $this->cartTotal($cart) - $alreadyOnCart));

        $this->putOnCart($giftCard, (int) $cart->getId(), $share);

        return $share;
    }

    public function reset(Cart $cart): void
    {
        GiftCardCartQuery::create()->filterByCartId($cart->getId())->delete();
    }

    /**
     * What the cards put on the cart add up to, in cents.
     */
    public function amountOnCart(Cart $cart): int
    {
        return array_sum($this->amountsOnCart((int) $cart->getId()));
    }

    /**
     * What the cart costs, taxes, discount and postage included, in cents.
     */
    public function cartTotal(Cart $cart): int
    {
        [$country, $state] = $this->taxationPlaceOf($cart);

        return GiftCardAmount::cents($cart->getTaxedAmount($country, true, $state, true));
    }

    /**
     * Whether the cards put on the cart pay for all of it, to the cent.
     */
    public function coversTheCart(Cart $cart): bool
    {
        $amountOnCart = $this->amountOnCart($cart);

        return $amountOnCart > 0 && $amountOnCart >= $this->cartTotal($cart);
    }

    /**
     * A cart that holds a gift card is not paid with gift cards: the card bought would pay for
     * itself.
     */
    public function holdsAGiftCard(Cart $cart): bool
    {
        $categoryId = TheliaGiftCard::getGiftCardCategoryId();

        if ($categoryId <= 0) {
            return false;
        }

        return ProductCategoryQuery::create()
            ->filterByCategoryId($categoryId)
            ->filterByDefaultCategory(true)
            ->filterByProductId(
                CartItemQuery::create()->filterByCartId($cart->getId())->select(['ProductId'])->find()->getData(),
                Criteria::IN,
            )
            ->exists();
    }

    private function spendableQuery(Customer $customer): GiftCardQuery
    {
        return GiftCardQuery::create()
            ->filterByBeneficiaryCustomerId($customer->getId())
            ->filterByStatus(1)
            ->filterByExpirationDate((new \DateTimeImmutable('today'))->format('Y-m-d'), Criteria::GREATER_EQUAL)
            ->where('COALESCE('.GiftCardTableMap::COL_SPEND_AMOUNT.', 0) < '.GiftCardTableMap::COL_AMOUNT);
    }

    private function remainingOn(GiftCard $giftCard): int
    {
        return max(0, GiftCardAmount::cents($giftCard->getAmount()) - GiftCardAmount::cents($giftCard->getSpendAmount()));
    }

    /**
     * A card is on one cart at a time: putting it on this one takes it off any other.
     */
    private function putOnCart(GiftCard $giftCard, int $cartId, int $cents): void
    {
        $giftCardCart = GiftCardCartQuery::create()->filterByGiftCardId($giftCard->getId())->findOne() ?? new GiftCardCart();

        $giftCardCart
            ->setGiftCardId($giftCard->getId())
            ->setCartId($cartId)
            ->setSpendAmount(GiftCardAmount::decimal($cents))
            ->save();
    }

    /**
     * @return array<int, int> cents by gift card id
     */
    private function amountsOnCart(int $cartId): array
    {
        $amounts = [];

        /** @var GiftCardCart $giftCardCart */
        foreach (GiftCardCartQuery::create()->filterByCartId($cartId)->find() as $giftCardCart) {
            $giftCardId = (int) $giftCardCart->getGiftCardId();
            $amounts[$giftCardId] = ($amounts[$giftCardId] ?? 0) + GiftCardAmount::cents($giftCardCart->getSpendAmount());
        }

        return $amounts;
    }

    /**
     * Where the cart is taxed: its delivery address, else the customer's default address, else
     * the shop's country — the order the core follows when it prices a cart being paid.
     *
     * @return array{0: Country, 1: State|null}
     */
    private function taxationPlaceOf(Cart $cart): array
    {
        $deliveryAddress = $cart->getCartAddressRelatedByAddressDeliveryId();

        if ($deliveryAddress?->getCountry() instanceof Country) {
            return [$deliveryAddress->getCountry(), $deliveryAddress->getState()];
        }

        $defaultAddress = $cart->getCustomer()?->getDefaultAddress();

        if ($defaultAddress?->getCountry() instanceof Country) {
            return [$defaultAddress->getCountry(), $defaultAddress->getState()];
        }

        return [Country::getDefaultCountry(), null];
    }
}
