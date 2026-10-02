<?php

namespace TheliaGiftCard\Service;

use Front\Front;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Domain\Module\Payment\PaymentCartContext;
use Thelia\Model\Address;
use Thelia\Model\Cart;
use Thelia\Model\ModuleQuery;
use Thelia\Module\Exception\DeliveryException;
use TheliaGiftCard\Model\Base\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\Map\GiftCardInfoCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardService
{
    public function __construct(
        protected RequestStack $requestStack,
        protected EventDispatcherInterface $dispatcher,
        private readonly ContainerInterface $container,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly PaymentCartContext $paymentCartContext,
        private readonly GiftCardCartSpending $giftCardCartSpending,
    ) {
    }

    public function getAvailableGiftCardAmount(GiftCard $giftCard): float
    {
        return $giftCard->getAmount() - $giftCard->getSpendAmount();
    }

    /**
     * @throws PropelException
     */
    public function getInfoGiftCard($code): ?array
    {
        $query = GiftCardInfoCartQuery::create();

        $giftCardJoin = new Join();
        $giftCardJoin->addExplicitCondition(
            GiftCardInfoCartTableMap::TABLE_NAME,
            'gift_card_id',
            '',
            GiftCardTableMap::TABLE_NAME,
            'ID'
        );
        $giftCardJoin->setJoinType(Criteria::RIGHT_JOIN);

        $query->addJoinObject($giftCardJoin, 'test-code-join');
        $query->where(GiftCardTableMap::COL_CODE.' = ?', $code, \PDO::PARAM_STR);

        $query
            ->withColumn(
                GiftCardTableMap::COL_CODE,
                'code'
            );

        $query
            ->withColumn(
                GiftCardTableMap::COL_AMOUNT,
                'amount'
            );

        $query
            ->withColumn(
                GiftCardTableMap::COL_EXPIRATION_DATE,
                'expirationDate'
            );

        $infosCard = $query->findOne();

        if (null !== $infosCard) {
            return [
                'message' => $infosCard->getBeneficiaryMessage(),
                'code' => $infosCard->getVirtualColumn('code'),
                'sponsorName' => $infosCard->getSponsorName(),
                'beneficiaryName' => $infosCard->getBeneficiaryName(),
                'beneficiaryAddress' => $infosCard->getBeneficiaryAddress(),
                'beneficiaryEmail' => $infosCard->getBeneficiaryEmail(),
                'amount' => $infosCard->getVirtualColumn('amount'),
                'expirationDate' => $infosCard->getVirtualColumn('expirationDate'),
            ];
        }

        return null;
    }

    public function reset(): void
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            if (null === $request || !$request->hasSession()) {
                return;
            }
            $cartId = $request->getSession()->getSessionCart($this->dispatcher)->getId();

            GiftCardCartQuery::create()
                ->filterByCartId($cartId)
                ->delete();
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError($ex->getMessage());
        }
    }

    /**
     * Whether the gift cards put on the cart pay for all of it, compared in cents.
     *
     * The cart is the one being judged (IsValidPaymentEvent, PaymentCartContext), the session
     * cart otherwise. The order kept in session is never read: the Thelia 3 checkout keeps the
     * delivery choices on the cart.
     */
    public function isGiftCardPayment(?Cart $cart = null): bool
    {
        $cart ??= $this->paymentCartContext->cart() ?? $this->sessionCart();

        return $cart instanceof Cart && $this->giftCardCartSpending->coversTheCart($cart);
    }

    private function sessionCart(): ?Cart
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session instanceof Session ? $session->getSessionCart($this->dispatcher) : null;
    }

    /**
     * @throws \Exception
     */
    public function getPostage(Cart $cart, Address $chosenDeliveryAddress, int $deliveryModuleId): float
    {
        if (!$deliveryModule = ModuleQuery::create()->findPk($deliveryModuleId)) {
            throw new \Exception(Translator::getInstance()->trans('Delivery Module missing', [], TheliaGiftCard::DOMAIN_NAME));
        }

        $moduleInstance = $deliveryModule->getDeliveryModuleInstance($this->container);

        $deliveryPostageEvent = new DeliveryPostageEvent($moduleInstance, $cart, $chosenDeliveryAddress);

        $this->eventDispatcher->dispatch(
            $deliveryPostageEvent,
            TheliaEvents::MODULE_DELIVERY_GET_POSTAGE
        );

        if (!$deliveryPostageEvent->isValidModule()) {
            throw new DeliveryException(Translator::getInstance()->trans('The delivery module is not valid.', [], Front::MESSAGE_DOMAIN));
        }

        $postage = $deliveryPostageEvent->getPostage()->getAmount();

        return round($postage, 2);
    }
}
