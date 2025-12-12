<?php

namespace TheliaGiftCard\Controller\Api;

use OpenApi\Annotations as OA;
use Symfony\Component\Routing\Annotation\Route;

use OpenApi\Controller\Front\BaseFrontOpenApiController;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use TheliaGiftCard\Form\ConsumeGiftCart;
use TheliaGiftCard\Service\GiftCardSpend;

/**
 * Class GiftCardListApiController
 * @Route("/open_api/gift-card", name="giftcards")
 */
class GiftCardSpendApiController extends BaseFrontOpenApiController
{
    /**
     * @Route("/spend", name="spend_giftcard", methods="POST")
     *
     * @OA\Post(
     *     path="/gift-card/spend",
     *     tags={"Gift Cards"},
     *     summary="Spend on cart amount from gift card",
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="consume_gift_card",
     *                     type="array",
     *                        @OA\Items(
     *                          @OA\Property(
     *                              property="amount_used",
     *                              type="number",
     *                            )
     *                       ),
     *                         @OA\Items(
     *                          @OA\Property(
     *                              property="gift_card_codes",
     *                              type="string",
     *                            )
     *                       ),
     *                 ),
     *                 example={"consume_gift_card":{"amount_used":10,"gift_card_codes":"53JM8TCW"}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *          response="400",
     *          description="Bad request",
     *          @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     * )
     */
    public function spend(
        Request                  $request,
        EventDispatcherInterface $dispatcher,
        Session                  $session,
        GiftCardSpend            $giftCardSpend
    ): JsonResponse
    {
        /** @var Cart $cart */
        $cart = $session->getSessionCart();
        $restAmount = 0;

        $data = json_decode($request->getContent(), true);

        if (!isset($data['consume_gift_card'])) {
            return new JsonResponse(
                ['error' => 'Missing consume_gift_card data'],
                400
            );
        }

        $consumeData = $data['consume_gift_card'];

        if (!isset($consumeData['amount_used']) || !isset($consumeData['gift_card_codes'])) {
            return new JsonResponse(
                ['error' => 'Missing required fields: amount_used or gift_card_codes'],
                400
            );
        }

        $amount = (float) $consumeData['amount_used'];
        $deliveryModuleId = $consumeData['delivery_module_id'] ?? null;

        //TODO: a mettre en configuration, en l'etat, aucun cumule avec les coupons
        $dispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::COUPON_CLEAR_ALL);

        $giftCardSpend->deleteGiftCardOnCart($cart);

        if (0 > $amount) {
            return new JsonResponse(["Success"]);
        }

        foreach ($consumeData['gift_card_codes'] as $code) {
            if ($restAmount > 0) {
                $amount = $amount + $restAmount;
            }

            $restAmount = $giftCardSpend->spendGiftCard($code, $amount, $deliveryModuleId);
        }

        if (!$session->getOrder()->getDeliveryModuleId()) {
            //need to set chossendeliveryID in session
            $session->getOrder()->setDeliveryModuleId($deliveryModuleId);
            $session->save();
        }

        return new JsonResponse(["Success"]);
    }
}