<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Controller\Front;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use TheliaGiftCard\Form\ActivateGiftCardToCustomerForm;
use TheliaGiftCard\Service\GiftCardActivationLimiter;
use TheliaGiftCard\Service\GiftCardActivationService;
use TheliaGiftCard\TheliaGiftCard;

#[Route('/gift-card', name: 'gift_card_')]
class GiftCardController extends BaseFrontController
{
    #[Route('/activate-code', name: 'activate', methods: 'POST')]
    public function activateGiftCardAction(
        Request $request,
        Translator $translator,
        ParserContext $parserContext,
        GiftCardActivationService $activationService,
        GiftCardActivationLimiter $activationLimiter,
    ): RedirectResponse|Response {
        $this->checkAuth();

        $form = $this->createForm(ActivateGiftCardToCustomerForm::getName());

        try {
            $codeForm = $this->validateForm($form);
            $customerId = (int) $request->getSession()->getCustomerUser()->getId();

            if (!$activationLimiter->allows($customerId, $request->getClientIp())) {
                throw new FormValidationException($translator->trans('Too many attempts. Please try again later.', [], TheliaGiftCard::DOMAIN_NAME));
            }

            if (!$activationService->attachToCustomer((string) $codeForm->get('code_gift_card')->getData(), $customerId)) {
                throw new FormValidationException($translator->trans('This code is not valid, not activated or already used.', [], TheliaGiftCard::DOMAIN_NAME));
            }

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $exception) {
            $message = $translator->trans('Please check your input: %s', ['%s' => $exception->getMessage()], TheliaGiftCard::DOMAIN_NAME);
        } catch (\Exception $exception) {
            Tlog::getInstance()->error('Gift card activation failed: '.$exception->getMessage());
            $message = $translator->trans('Sorry, an error occurred.', [], TheliaGiftCard::DOMAIN_NAME);
        }

        $form->setErrorMessage($message);

        $parserContext
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }
}
