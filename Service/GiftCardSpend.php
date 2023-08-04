<?php

namespace TheliaGiftCard\Service;

use Exception;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\Cart;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardSpend
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly GiftCardService $giftCardService
    )
    {

    }

    /**
     * @throws PropelException
     * @throws Exception
     */
    public function spendGiftCard(string $code, float $amount, int $deliveryModuleId): float
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();

        /** @var Customer $customer */
        $customer = $session->getCustomerUser();

        /** @var Cart $cart */
        $cart = $session->getSessionCart();

        /** @var Order $order */
        $order = $session->getOrder();

        if (!$customer || !$cart || !$order) {
            throw new Exception(Translator::getInstance()->trans("Missing parameter !", [], TheliaGiftCard::DOMAIN_NAME));
        }

        /** @var Address $chosenDeliveryAddress */
        if (!$chosenDeliveryAddress = AddressQuery::create()->findPk($order->getChoosenDeliveryAddress())) {
            throw new Exception(Translator::getInstance()->trans("Delivery address not set yet", [], TheliaGiftCard::DOMAIN_NAME));
        }

        /** @var GiftCard $giftCard */
        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByStatus(1)
            ->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT)
            ->findOne();

        if (null == $giftCard) {
            throw new Exception(Translator::getInstance()->trans('Gift Card invalid', [], TheliaGiftCard::DOMAIN_NAME));
        }

        $postage = $this->giftCardService->getPostage($cart, $chosenDeliveryAddress, $deliveryModuleId);
        $availableAmount = $this->giftCardService->getAvailableGiftCardAmount($giftCard);

        $orderTotal = $cart->getTaxedAmount($chosenDeliveryAddress->getCountry()) + $postage;

        $realAmountToSpend = $amount;

        if ($availableAmount < $amount) {
            $realAmountToSpend = $availableAmount;
        }

        if ($orderTotal <= $realAmountToSpend) {
            $this->setGiftCardOnCart($giftCard, $orderTotal, $cart->getId());
            return 0;
        }

        $this->setGiftCardOnCart($giftCard, $realAmountToSpend, $cart->getId());

        return $realAmountToSpend;
    }

    public function setGiftCardOnCart(GiftCard $giftCard, float $amount, int $cartId): void
    {
        $newGiftCardOnCart = GiftCardCartQuery::create()
            ->filterByGiftCardId($giftCard->getId())
            ->findOneOrCreate();

        $newGiftCardOnCart
            ->setGiftCardId($giftCard->getId())
            ->setCartId($cartId)
            ->setSpendAmount($amount)
            ->save();
    }

    /**
     * @throws Exception
     */
    public function deleteGiftCardOnCart(Cart $cart): void
    {
        GiftCardCartQuery::create()
            ->filterByCartId($cart->getId())
            ->delete();
    }
}