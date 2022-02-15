<?php

namespace TheliaGiftCard\Controller\Api;

use OpenApi\Annotations as OA;

use OpenApi\Controller\Front\BaseFrontOpenApiController;
use Propel\Runtime\ActiveQuery\Criteria;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard as MainClassTheliaGiftCard;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardActivateApiController
 * @Route("/open_api/gift-card", name="giftcards")
 */
class GiftCardActivateApiController extends BaseFrontOpenApiController
{
    /**
     * @Route("/activate", name="activate_giftcard", methods="POST")
     *
     * @OA\Post(
     *     path="/gift-card/activate",
     *     tags={"Gift Cards"},
     *     summary="Activate gift card on customer",
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="activate_gift_card_to_customer",
     *                     type="array",
     *                        @OA\Items(
     *                          @OA\Property(
     *                              property="code_gift_card",
     *                              type="string",
     *                              example=""
     *                            )
     *                       ),
     *                 ),
     *                 example={"activate_gift_card_to_customer":{"code_gift_card":"TEST"}}
     *             )
     *         )
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
    public function activate(Request $request)
    {
        $form = $this->createForm('activate.gift.card.to.customer',FormType::class, [], ['csrf_protection' => false]);

        $codeForm = $this->validateForm($form);
        $code = $codeForm->get('code_gift_card')->getData();

        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
            ->findOne();

        if (null === $giftCard) {
            throw new \Exception(Translator::getInstance()->trans("Code incorrecte ou non activable"));
        }

        $currentCustomerId = $request->getSession()->getCustomerUser();

        $giftCard
            ->setBeneficiaryCustomerId($currentCustomerId->getId())
            ->save();

        return $this->jsonResponse(
            json_encode(Translator::getInstance()->trans("Activation carte cadeau reussie",[MainClassTheliaGiftCard::DOMAIN_NAME]))
        );
    }
}