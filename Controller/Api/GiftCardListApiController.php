<?php

namespace TheliaGiftCard\Controller\Api;

use OpenApi\Annotations as OA;
use OpenApi\Controller\Front\BaseFrontOpenApiController;

use OpenApi\Model\Api\ModelFactory;
use Propel\Runtime\ActiveQuery\Criteria;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Map\ProductI18nTableMap;

use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\Api\GiftCard as OpenApiGiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardListApiController
 * @Route("/open_api/gift-card", name="giftcards-spend")
 */
class GiftCardListApiController extends BaseFrontOpenApiController
{
    /**
     * @Route("/list", name="get_giftcards", methods="GET")
     *
     * @OA\Get (
     *     path="/gift-card/list",
     *     tags={"Gift Cards"},
     *     summary="Activate gift card on customer",
     *     @OA\Parameter(
     *          name="show-expired",
     *          in="query",
     *          @OA\Schema(
     *              type="boolean"
     *          )
     *     ),
     *     @OA\Parameter(
     *          name="show-desactivate",
     *          in="query",
     *          @OA\Schema(
     *              type="boolean"
     *          )
     *     ),
     *     @OA\Parameter(
     *          name="show-on-cart",
     *          in="query",
     *          @OA\Schema(
     *              type="boolean"
     *          )
     *     ),
     *     @OA\Parameter(
     *          name="card-id",
     *          in="query",
     *          @OA\Schema(
     *              type="integer"
     *          )
     *     ),
     *     @OA\Response(
     *          response="200",
     *          description="Success",
     *     ),
     *     @OA\Response(
     *          response="400",
     *          description="Bad request",
     *          @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     * )
     */
    public function getGiftCards(Request $request, EventDispatcherInterface $dispatcher, ModelFactory $modelFactory)
    {
        if ($this->cartHasGiftCard($request->getSession(), $dispatcher)) {
            return $this->JsonResponse([]);
        }

        $locale = $request->getSession()->getLang()->getLocale();

        $search = GiftCardQuery::create()
            ->useProductQuery('', Criteria::LEFT_JOIN)
                ->useProductI18nQuery('', Criteria::LEFT_JOIN)
                    ->filterByLocale($locale)
                    ->_or()
                    ->filterByLocale(null, Criteria::ISNULL)//TODO: Est-ce bien necessaire ?? ça fonctionne avec, à voir (note à moi même)
                ->endUse()
                ->endUse()
            ->withColumn(ProductI18nTableMap::TABLE_NAME . '.' . 'title', 'PRODUCT_TITLE');

        if (!$request->get('show-expired')) {
            $search->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT);
        }

        if (!$request->get('show-desactivate')) {
            $search->filterByStatus(1);
        }

        if ($cardId = $request->get('card-id')) {
            $search->filterById($cardId);
        }

        /** @var Customer $customer */
        $customer = $request->getSession()->getCustomerUser();

        $search->filterByBeneficiaryCustomerId($customer->getId(), Criteria::EQUAL);

        $cart = $request->getSession()->getSessionCart($dispatcher);

        $search
            ->useGiftCardCartQuery()
                ->withColumn("SUM(" . GiftCardCartTableMap::COL_SPEND_AMOUNT . ")", 'CART_SPEND_AMOUNT')
            ->endUse();

        if ($cart && $request->get('show-on-cart')) {
            $search
                ->useGiftCardCartQuery()
                    ->filterByCartId($cart->getId())
                ->endUse();
        }

        $gifCards = $search->groupby(GiftCardTableMap::COL_ID)->find();

        return $this->jsonResponse(
            json_encode(array_map(function (GiftCard $giftCard) use ($modelFactory) {
                /** @var OpenApiGiftCard $openGifCard */
                $openGifCard = $modelFactory->buildModel('GiftCard', $giftCard);
                $openGifCard->validate(self::GROUP_READ);
                return $openGifCard;

            }, iterator_to_array($gifCards))
        ));
    }

    /**
     * @return bool
     */
    public function cartHasGiftCard(Session $session, EventDispatcherInterface $dispatcher)
    {
        $cart = $session->getSessionCart($dispatcher);
        if ($cart == null) {
            return false;
        }

        $cartItems = $cart->getCartItems();
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->getProduct();
            if ($product == null) {
                continue;
            }
            if ($product->getDefaultCategoryId() == ConfigQuery::read(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME)) {
                return true;
            }
        }
        return false;
    }

}