<?php

namespace TheliaGiftCard\Service;

use Exception;
use Front\Front;
use PDO;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
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
        protected RequestStack             $requestStack,
        protected EventDispatcherInterface $dispatcher,
        private readonly ContainerInterface       $container,
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
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
        $query->where(GiftCardTableMap::COL_CODE . ' = ?', $code, PDO::PARAM_STR);

        $query
            ->withColumn(GiftCardTableMap::COL_CODE,
                'code'
            );

        $query
            ->withColumn(GiftCardTableMap::COL_AMOUNT,
                'amount'
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
            ];
        }

        return null;
    }

    public function reset(): void
    {
        try {
            $cartId = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart()->getId();

            GiftCardCartQuery::create()
                ->filterByCartId($cartId)
                ->delete();

        } catch (Exception $ex) {
            Tlog::getInstance()->addError($ex->getMessage());
        }
    }

    public function isGiftCardPayment():bool
    {
        $request = $this->requestStack->getCurrentRequest();

        /** @var Cart $cart */
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();
        $order = $request->getSession()->getOrder();

        if (!$order->getDeliveryModuleId()) {
            return false;
        }

        if (!$chosenDeliveryAddress = AddressQuery::create()->findPk($order->getChoosenDeliveryAddress())) {
            return false;
        }

        $totalCartAmount = round($cart->getTaxedAmount($chosenDeliveryAddress->getCountry(), true, $chosenDeliveryAddress->getState()), 2);
        $orderPostage = $this->getPostage($cart, $chosenDeliveryAddress, $order->getDeliveryModuleId());
        $total = $totalCartAmount + $orderPostage;

        return $total == TheliaGiftCard::getTotalCartGiftCardAmount($cart->getId());
    }

    /**
     * @throws Exception
     */
    public function getPostage(Cart $cart, Address $chosenDeliveryAddress, int $deliveryModuleId): float
    {
        if (!$deliveryModule = ModuleQuery::create()->findPk($deliveryModuleId)) {
            throw new Exception(Translator::getInstance()->trans('Delivery Module missing', [], TheliaGiftCard::DOMAIN_NAME));
        }

        $moduleInstance = $deliveryModule->getDeliveryModuleInstance($this->container);

        $deliveryPostageEvent = new DeliveryPostageEvent($moduleInstance, $cart, $chosenDeliveryAddress);

        $this->eventDispatcher->dispatch(
            $deliveryPostageEvent,
            TheliaEvents::MODULE_DELIVERY_GET_POSTAGE
        );

        if (!$deliveryPostageEvent->isValidModule()) {
            throw new DeliveryException(
                Translator::getInstance()->trans('The delivery module is not valid.', [], Front::MESSAGE_DOMAIN)
            );
        }

        $postage = $deliveryPostageEvent->getPostage()->getAmount();

        return round($postage, 2);
    }
}