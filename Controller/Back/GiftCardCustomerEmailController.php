<?php

declare(strict_types=1);

namespace TheliaGiftCard\Controller\Back;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use TheliaGiftCard\Exception\GiftCardNotFoundException;
use TheliaGiftCard\Form\GiftCardCustomerEmailForm;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Sends a gift card by email from the back-office, to the address the administrator typed.
 */
class GiftCardCustomerEmailController extends BaseAdminController
{
    #[Route('/admin/module/theliagiftcard/giftcard/send', name: 'gift_card_mail', methods: 'POST')]
    public function createOrUpdateAction(
        ParserContext $parser,
        GiftCardEmailService $giftCardEmailService,
    ): RedirectResponse|Response|null {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(GiftCardCustomerEmailForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            $giftCardEmailService->sendByEmail(
                (string) $data['gift_card_code'],
                (string) $data['to'],
                $data['email_subject'] ?? null,
                $data['email_text'] ?? null,
            );

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $error) {
            $message = $error->getMessage();
        } catch (GiftCardNotFoundException) {
            $message = $this->getTranslator()->trans('No gift card carries this code.', [], TheliaGiftCard::DOMAIN_NAME);
        } catch (\Exception $exception) {
            Tlog::getInstance()->error('Gift card email not sent: '.$exception->getMessage());
            $message = $this->getTranslator()->trans('The gift card could not be sent.', [], TheliaGiftCard::DOMAIN_NAME);
        }

        $form->setErrorMessage($message);
        $parser
            ->addForm($form)
            ->setGeneralError($message);

        return $this->generateErrorRedirect($form);
    }
}
