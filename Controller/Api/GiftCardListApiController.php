<?php

namespace TheliaGiftCard\Controller\Api;

use OpenApi\Annotations as OA;
use Symfony\Component\Routing\Annotation\Route;

use OpenApi\Exception\OpenApiException;
use Propel\Runtime\Exception\PropelException;
use OpenApi\Controller\Front\BaseFrontOpenApiController;
use OpenApi\Model\Api\ModelFactory;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Validator\Constraints\Json;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Map\ProductI18nTableMap;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\Api\GiftCard as OpenApiGiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

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
     * @throws PropelException|OpenApiException
     */
    public function getGiftCards(Request $request, EventDispatcherInterface $dispatcher, ModelFactory $modelFactory): JsonResponse
    {
        if ($this->cartHasGiftCard($request->getSession(), $dispatcher)) {
            return new JsonResponse('');
        }

        /** @var Customer $customer */
        $customer = $request->getSession()->getCustomerUser();
        $cart = $request->getSession()->getSessionCart($dispatcher);
        $locale = $request->getSession()->getLang()->getLocale();

        $giftCards = GiftCardQuery::create()
            ->useProductQuery('')
            ->useProductI18nQuery('', Criteria::LEFT_JOIN)
            ->filterByLocale($locale)
            ->_or()
            ->filterByLocale(null, Criteria::ISNULL)//TODO: Est-ce bien necessaire ?? ça fonctionne avec, à voir (note à moi même)
            ->endUse()
            ->endUse()
            ->withColumn(ProductI18nTableMap::TABLE_NAME . '.' . 'title', 'PRODUCT_TITLE');

        if (!$request->get('show-expired')) {
            $giftCards->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT);
        }

        if (!$request->get('show-desactivate')) {
            $giftCards->filterByStatus(1);
        }

        if ($cardId = $request->get('card-id')) {
            $giftCards->filterById($cardId);
        }
        $giftCards->filterByBeneficiaryCustomerId($customer->getId(), Criteria::EQUAL);

        $giftCards
            ->useGiftCardCartQuery()
            ->withColumn("SUM(" . GiftCardCartTableMap::COL_SPEND_AMOUNT . ")", 'CART_SPEND_AMOUNT')
            ->endUse();

        if ($cart && $request->get('show-on-cart')) {
            $giftCards
                ->useGiftCardCartQuery()
                ->filterByCartId($cart->getId())
                ->endUse();
        }

        $giftCards->groupby(GiftCardTableMap::COL_ID)->find();

        return new JsonResponse(
            array_map(function (GiftCard $giftCard) use ($modelFactory) {
                /** @var OpenApiGiftCard $openGifCard */
                $openGifCard = $modelFactory->buildModel('GiftCard', $giftCard);
                $openGifCard->validate(self::GROUP_READ);
                return $openGifCard;

            }, iterator_to_array($giftCards)
            )
        );
    }

    public function cartHasGiftCard(Session $session, EventDispatcherInterface $dispatcher): bool
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