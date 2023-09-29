<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Exception;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use TheliaGiftCard\Form\ActivateGiftCardToCustomerForm;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

#[Route('/gift-card', name: 'gift_card_')]
class GiftCardController extends BaseFrontController
{
    #[Route('/activate-code', name: 'activate', methods: 'POST')]
    public function activateGiftCardAction(
        Request    $request,
        Translator $translator,
        ParserContext $parserContext
    ): RedirectResponse|Response
    {
        $this->checkAuth();

        $form = $this->createForm(ActivateGiftCardToCustomerForm::getName());

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

            $currentCustomerId = $request->getSession()->getCustomerUser()->getId();

            $giftCard
                ->setBeneficiaryCustomerId($currentCustomerId)
                ->save();

            return $this->generateSuccessRedirect($form);

        } catch (FormValidationException $error_message) {
            $message = $translator->trans(
                "Please check your input: %s",
                [
                    '%s' => $error_message->getMessage()
                ],
                TheliaGiftCard::DOMAIN_NAME
            );

        } catch (Exception $e) {
            $message = $translator->trans(
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