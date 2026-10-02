<?php

declare(strict_types=1);

namespace TheliaGiftCard\Service;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

/**
 * @deprecated since 3.2.0, use GiftCardCartSpending: it spends several cards at once, within the
 *             amount asked for, and only the cards of the customer
 */
class GiftCardSpend
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly GiftCardService $giftCardService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly GiftCardCartSpending $giftCardCartSpending,
    ) {
    }

    /**
     * Puts one card on the session cart. The postage is the one the cart already holds: the
     * delivery module id is kept in the signature for the callers of the previous releases.
     *
     * @return float the amount put on the cart, or 0 when the card pays for all of it
     *
     * @throws PropelException
     * @throws \Exception
     */
    public function spendGiftCard(string $code, float $amount, int $deliveryModuleId): float
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = (null !== $request && $request->hasSession()) ? $request->getSession() : null;

        if (!$session instanceof Session || null === $session->getCustomerUser()) {
            throw new \Exception(Translator::getInstance()->trans('Missing parameter !', [], TheliaGiftCard::DOMAIN_NAME));
        }

        /** @var Cart $cart */
        $cart = $session->getSessionCart($this->dispatcher);

        /** @var GiftCard|null $giftCard */
        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByStatus(1)
            ->where(GiftCardTableMap::COL_SPEND_AMOUNT.' < '.GiftCardTableMap::COL_AMOUNT)
            ->findOne();

        if (null === $giftCard) {
            throw new \Exception(Translator::getInstance()->trans('Gift Card invalid', [], TheliaGiftCard::DOMAIN_NAME));
        }

        $spent = $this->giftCardCartSpending->spendOne($giftCard, $cart, GiftCardAmount::cents($amount));

        return $this->giftCardCartSpending->coversTheCart($cart) ? 0 : $spent / 100;
    }

    public function setGiftCardOnCart(GiftCard $giftCard, float $amount, int $cartId): void
    {
        $cart = CartQuery::create()->findPk($cartId);

        if (null !== $cart) {
            $this->giftCardCartSpending->spendOne($giftCard, $cart, GiftCardAmount::cents($amount));
        }
    }

    /**
     * @throws \Exception
     */
    public function deleteGiftCardOnCart(Cart $cart): void
    {
        $this->giftCardCartSpending->reset($cart);
    }
}
