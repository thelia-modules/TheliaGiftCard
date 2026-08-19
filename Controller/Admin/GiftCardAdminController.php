<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Admin;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Admin\BaseAdminController;

use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use Thelia\Core\HttpFoundation\JsonResponse;
use TheliaGiftCard\Hook\HookConfigurationManager;
use Propel\Runtime\ActiveQuery\Criteria;

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
    public function showGiftCard(string $code, Request $request): JsonResponse
    {
        $tab = [];

        if (!$giftCard = GiftCardQuery::create()->findOneByCode($code)) {
            return new JsonResponse($tab);
        }

        if ($infos = GiftCardInfoCartQuery::create()->filterByGiftCardId($giftCard->getId())->findOne()) {
            $tab['beneficiary_address'] = $infos->getBeneficiaryAddress();
        }

        $tab['amount'] = $giftCard->getAmount();
        $tab['expiration_date'] = $giftCard->getExpirationDate()?->format('Y-m-d');
        $tab['card_id'] = $giftCard->getId();

        $orders = [];
        $ordersGiftCard = GiftCardOrderQuery::create()
            ->filterByGiftCardId($giftCard->getId())
            ->find();

        /** @var GiftCardOrder $orderGiftCard */
        foreach ($ordersGiftCard as $orderGiftCard) {
            $orderModel = $orderGiftCard->getOrder();
            $date = $orderGiftCard->getCreatedAt();
            $locale = $request->hasSession()
                ? $request->getSession()->getLang()->getLocale()
                : (\Thelia\Model\LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US');
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

    /**
     */
    #[Route('/show', name: 'gift_card_show_list')]
    public function showGiftCardsList(Request $request): JsonResponse
    {
        $json = [
            "data" => []
        ];

        $giftCards = GiftCardQuery::create();

        $this->applyOrder($request, $giftCards);

        if ($this->getOffset($request) && $this->getLength($request)) {
            $giftCards
                ->offset($this->getOffset($request))
                ->limit($this->getLength($request));
        }

        $giftCards->find();

        $queryCount = clone $giftCards;

        foreach ($giftCards as $giftCard) {
            $currentData = [
                $giftCard->getId(),
                $giftCard->getOrderId(),
                $giftCard->getCode(),
                $giftCard->getSponsorCustomerId(),
                $giftCard->getBeneficiaryCustomerId(),
                $giftCard->getAmount(),
                $giftCard->getSpendAmount(),
                $giftCard->getExpirationDate('d-m-Y'),
                $giftCard->getCreatedAt('d-m-Y'),
                $giftCard->getStatus(),
                $giftCard->getId(),
                $giftCard->getId()
            ];

            $json['data'][] = $currentData;
        }

        $json["recordsTotal"] = $queryCount->count();
        $json["recordsFiltered"] = $queryCount->count();

        return new JsonResponse($json);
    }

    protected function getLength(Request $request): int
    {
        return (int)$request->attributes->get('length', $request->query->get('length', $request->request->get('length')));
    }

    protected function getOffset(Request $request): int
    {
        return (int)$request->attributes->get('start', $request->query->get('start', $request->request->get('start')));
    }

    protected function getSearchValue(Request $request): ?string
    {
        $search = $request->attributes->get('search', $request->query->get('search', $request->request->get('search')));
        return $search ? (string)$search['value'] : null;
    }

    protected function getOrderDir(Request $request): ?string
    {
        $order = $request->attributes->get('order', $request->query->get('order', $request->request->get('order')));
        if (null === $order) {
            return null;
        }
        return (string)$order[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    protected function applyOrder(Request $request, GiftCardQuery $query): void
    {
        $columnName = $this->getOrderColumnName($request);
        if (null !== $columnName) {
            $query->orderBy(
                $columnName,
                $this->getOrderDir($request)
            );
        }
    }

    protected function getOrderColumnName(Request $request)
    {
        $order = $request->attributes->get('order', $request->query->get('order', $request->request->get('order')));
        return $order ?
            HookConfigurationManager::getdefineColumnsDefinition()[(int)$order[0]['column']]['orm'] : null;
    }
}
