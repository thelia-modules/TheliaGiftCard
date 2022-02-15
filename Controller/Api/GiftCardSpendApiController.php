<?php

namespace TheliaGiftCard\Controller\Api;

use OpenApi\Annotations as OA;
use OpenApi\Controller\Front\BaseFrontOpenApiController;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Request;
use TheliaGiftCard\Form\ConsumeGiftCart;
use TheliaGiftCard\Service\GiftCardService;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardListApiController
 * @Route("/open_api/gift-card", name="giftcards")
 */
class GiftCardSpendApiController extends BaseFrontOpenApiController
{
    /**
     * @var GiftCardService
     */
    private $giftCardService;

    public function __construct(GiftCardService $giftCardService)
    {
       $this->giftCardService = $giftCardService;
    }

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
    public function spend(Request $request, EventDispatcherInterface $dispatcher)
    {
        $form = $this->createForm(ConsumeGiftCart::getName(), FormType::class, [], ['csrf_protection' => false]);

        $amountForm = $this->validateForm($form);

        //TODO: a mettre en configuration, en l'etat, aucun cumule avec les coupons
        $dispatcher->dispatch(new DefaultActionEvent(),TheliaEvents::COUPON_CLEAR_ALL);

        $amount = $amountForm->get('amount_used')->getData();
        $codes = $amountForm->get('gift_card_codes')->getData();
        $deliveryModuleId = $amountForm->get('delivery_module_id')->getData();

        $customer = $request->getSession()->getCustomerUser();
        $cart = $request->getSession()->getSessionCart();
        $order = $request->getSession()->getOrder();

        $restAmount = 0;

        foreach (explode(',', $codes) as $code) {
            if ($restAmount > 0) {
                $amount = $amount + $restAmount;
            }

            $restAmount = $this->giftCardService->spendGiftCard($code, $amount, $cart, $order, $customer, $deliveryModuleId);
        }

        return $this->jsonResponse(
            json_encode("Success")
        );
    }
}