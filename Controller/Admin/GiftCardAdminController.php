<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Admin\BaseAdminController;

use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use Thelia\Core\HttpFoundation\JsonResponse;
use TheliaGiftCard\Hook\HookConfigurationManager;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\OrderQuery;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardAdminController
 * @Route("/admin/module/theliagiftcard", name="gift_card_admin") 
 */
class GiftCardAdminController extends BaseAdminController
{
    /**
     * @Route("/show/{code}", name="show_code") 
     */
    public function showGiftCardAction($code, Request $request)
    {
        $tab = [];;
        $giftCard = GiftCardQuery::create()->findOneByCode($code);

        if ($giftCard) {
            /** @var \DateTime $date */
            $date = $giftCard->getExpirationDate();
            $tab['amount'] = $giftCard->getAmount();
            $tab['expiration_date'] = $date->format('Y-m-d');
            $tab['card_id'] = $giftCard->getId();

            $orders = [];
            $ordersGiftCard = GiftCardOrderQuery::create()
                ->filterByGiftCardId($giftCard->getId())
                ->find();

            /** @var GiftCardOrder $orderGiftCard */
            foreach ($ordersGiftCard as $orderGiftCard) {
                $orderModel = $orderGiftCard->getOrder();
                $date = $orderGiftCard->getCreatedAt();
                $orderStatus = $orderModel->getOrderStatus()->setLocale($request->getSession()->getLang()->getLocale())->getTitle();

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

    /**
     * @Route("/show", name="show") 
     */
    public function showAction(Request $request)
    {

        $json = [
            "recordsTotal" => 0,
            "recordsFiltered" => 0,
            "data" => []
        ];

        if (true || $request->isXmlHttpRequest()) {

            $request->getSession()->save();

            $transactionOrders = GiftCardQuery::create();

            $queryCount = clone $transactionOrders;

            $this->applySearch($request, $transactionOrders);
            $this->applyOrder($request, $transactionOrders);

            if ($this->getOffset($request) && $this->getLength($request)) {
                $transactionOrders
                    ->offset($this->getOffset($request))
                    ->limit($this->getLength($request));
            }

            $transactionOrders->find();
            $queryCount = clone $transactionOrders;

            foreach ($transactionOrders as $transactionOrder) {
                $currentData = [
                    $transactionOrder->getId(),
                    $transactionOrder->getOrderId(),
                    $transactionOrder->getCode(),
                    $transactionOrder->getSponsorCustomerId(),
                    $transactionOrder->getBeneficiaryCustomerId(),
                    $transactionOrder->getAmount(),
                    $transactionOrder->getSpendAmount(),
                    $transactionOrder->getExpirationDate('d-m-Y'),
                    $transactionOrder->getCreatedAt('d-m-Y'),
                    $transactionOrder->getStatus(),
                    $transactionOrder->getId(),
                ];

                $currentData[] = $transactionOrder->getId();
                $json['data'][] = $currentData;
            }
            $json["recordsTotal"] = $queryCount->count();
            $json["recordsFiltered"] = $queryCount->count();
        }

        $jsonResponce = new JsonResponse($json);

        return $jsonResponce;
    }

    protected function getLength(Request $request)
    {
        return (int)$request->get('length');
    }

    protected function getOffset(Request $request)
    {
        return (int)$request->get('start');
    }

    protected function getSearchValue(Request $request)
    {
        return $request->get('search') ? (string)$request->get('search')['value'] : null;
    }

    protected function getOrderDir(Request $request)
    {
        $order = $request->get('order');
        if (null === $order){
            return null;
        }
        return (string)$order[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    protected function applyOrder(Request $request, GiftCardQuery $query)
    {
        $columnName = $this->getOrderColumnName($request);
        if (null !== $columnName){
            $query->orderBy(
                $columnName,
                $this->getOrderDir($request)
            );
        }
    }

    protected function getOrderColumnName(Request $request)
    {
        $columnDefinition = $request->get('order') ?
            HookConfigurationManager::getdefineColumnsDefinition()[(int)$request->get('order')[0]['column']]['orm'] : null;
        return $columnDefinition;
    }

    protected function applySearch(Request $request, GiftCardQuery $query)
    {
        $value = $this->getSearchValue($request);

        if (strlen($value) > 2) {
            $query
                ->filterByDescription('%' . $value . '%', Criteria::LIKE)
                ->_or()
                ->filterByAmount('%' . $value . '%', Criteria::LIKE);
        }
    }
}
