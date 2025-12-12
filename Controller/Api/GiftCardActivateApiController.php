<?php

namespace TheliaGiftCard\Controller\Api;

use Exception;
use OpenApi\Annotations as OA;
use Symfony\Component\Routing\Annotation\Route;

use OpenApi\Controller\Front\BaseFrontOpenApiController;
use Propel\Runtime\ActiveQuery\Criteria;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

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
     * @throws Exception
     */
    public function activate(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['activate_gift_card_to_customer'])) {
            return $this->jsonResponse(
                json_encode(['error' => 'Missing activate_gift_card_to_customer data']),
                400
            );
        }

        $activateData = $data['activate_gift_card_to_customer'];

        if (!isset($activateData['code_gift_card']) || empty($activateData['code_gift_card'])) {
            return $this->jsonResponse(
                json_encode(['error' => 'Missing required field: code_gift_card']),
                400
            );
        }

        $code = $activateData['code_gift_card'];

        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
            ->findOne();

        if (null === $giftCard) {
            throw new Exception(Translator::getInstance()->trans("Code incorrecte ou non activable", [], TheliaGiftCard::DOMAIN_NAME));
        }

        $currentCustomerId = $request->getSession()->getCustomerUser();

        $giftCard
            ->setBeneficiaryCustomerId($currentCustomerId->getId())
            ->save();

        return $this->jsonResponse(
            json_encode(Translator::getInstance()->trans("Activation carte cadeau reussie", [], TheliaGiftCard::DOMAIN_NAME))
        );
    }
}