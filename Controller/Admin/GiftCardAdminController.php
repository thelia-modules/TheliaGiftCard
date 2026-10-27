<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Admin;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Currency;
use TheliaGiftCard\TheliaGiftCard;

use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use Thelia\Core\HttpFoundation\JsonResponse;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Class GiftCardAdminController
 */
#[Route('/admin/module/theliagiftcard')]
class GiftCardAdminController extends BaseAdminController
{
    /**
     * @throws PropelException
     */
    #[Route('/show/{code}', name: 'gift_card_admin')]
    public function showGiftCard(string $code, Request $request): JsonResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::VIEW)) {
            return $response;
        }

        $tab = [];

        if (!$giftCard = GiftCardQuery::create()->findOneByCode($code)) {
            return new JsonResponse(['message' => 'Unknown gift card'], Response::HTTP_NOT_FOUND);
        }

        // The language of the interface, as the list reads it (set on the request by the back office).
        $locale = $request->getLocale();
        $info = GiftCardInfoCartQuery::create()->filterByGiftCardId($giftCard->getId())->findOne();
        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $currencyCode = (string) Currency::getDefaultCurrency()->getCode();
        $spent = (float) $giftCard->getSpendAmount();

        $tab['beneficiary_address'] = $info?->getBeneficiaryAddress();
        // Read only in the edit window: what the buyer wrote, and what is left of the card.
        $tab['beneficiary_message'] = (string) $info?->getBeneficiaryMessage();
        $tab['spent'] = (string) $money->formatCurrency($spent, $currencyCode);
        $tab['remaining'] = (string) $money->formatCurrency(max(0.0, (float) $giftCard->getAmount() - $spent), $currencyCode);

        $tab['amount'] = $giftCard->getAmount();
        $tab['expiration_date'] = $giftCard->getExpirationDate()?->format('Y-m-d');
        $tab['card_id'] = $giftCard->getId();

        $orders = [];
        $ordersGiftCard = GiftCardOrderQuery::create()
            ->filterByGiftCardId($giftCard->getId())
            ->filterBySpent()
            ->find();

        /** @var GiftCardOrder $orderGiftCard */
        foreach ($ordersGiftCard as $orderGiftCard) {
            $orderModel = $orderGiftCard->getOrder();
            $date = $orderGiftCard->getCreatedAt();
            $orderStatus = $orderModel->getOrderStatus()->setLocale($locale)->getTitle();

            $orders[] = [
                'ORDER_REF' => $orderGiftCard->getOrder()->getRef(),
                'SPEND_AMOUNT' => $orderGiftCard->getSpendAmount(),
                'DATE' => $date->format('Y-m-d'),
                'ORDER_STATUS' => $orderStatus
            ];
        }

        $tab['HISTORY'] = $orders;

        return new JsonResponse($tab);
    }
}
