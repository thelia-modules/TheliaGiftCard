<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Exception;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardController
 * @Route("/gift-card", name="gift_card")
 */
class GiftCardController extends BaseFrontController
{
    /**
     * @Route("/activate-code", name="activate_code")
     */
    public function activateGiftCardAction(Request $request, ParserContext $parserContext): RedirectResponse|Response|null
    {
        $this->checkAuth();

        $form = $this->createForm('activate_gift_card_to_customer');

        try {
            $codeForm = $this->validateForm($form);
            $code = $codeForm->get('code_gift_card')->getData();

            $giftCard = GiftCardQuery::create()
                ->filterByCode($code)
                ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
                ->findOne();

            if (null === $giftCard) {
                throw new FormValidationException(Translator::getInstance()->trans("Code incorrecte ou  non activé"));
            }

            $currentCustomerId = $request->getSession()->getCustomerUser()?->getId();

            if (null === $currentCustomerId) {
                throw new FormValidationException('No customer connected');
            }

            $giftCard
                ->setBeneficiaryCustomerId($currentCustomerId)
                ->save();

            return $this->generateSuccessRedirect($form);

        } catch (FormValidationException $error_message) {
            $message = Translator::getInstance()->trans(
                "Please check your input: %s",
                [
                    '%s' => $error_message->getMessage()
                ],
                TheliaGiftCard::DOMAIN_NAME
            );

        } catch (Exception $e) {
            $message = Translator::getInstance()->trans(
                "Sorry, an error occurred: %s",
                [
                    '%s' => $e->getMessage()
                ],
                TheliaGiftCard::DOMAIN_NAME
            );
        }

        $form->setErrorMessage($message);
        $parserContext
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }
}