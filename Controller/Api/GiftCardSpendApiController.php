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
        EventDispatcherInterface $dispatcher,
        Session                  $session,
        GiftCardSpend            $giftCardSpend
    ): JsonResponse
    {
        /** @var Cart $cart */
        $cart = $session->getSessionCart();
        $restAmount = 0;

        $form = $this->createForm(ConsumeGiftCart::getName(), FormType::class, [], ['csrf_protection' => false]);

        $amountForm = $this->validateForm($form);

        $amount = $amountForm->get('amount_used')->getData();
        $deliveryModuleId = $amountForm->get('delivery_module_id')->getData();

        //TODO: a mettre en configuration, en l'etat, aucun cumule avec les coupons
        $dispatcher->dispatch(new DefaultActionEvent(), TheliaEvents::COUPON_CLEAR_ALL);

        $giftCardSpend->deleteGiftCardOnCart($cart);

        if (0 > $amount) {
            return new JsonResponse(["Success"]);
        }

        foreach ($amountForm->get('gift_card_codes')->getData() as $code) {
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