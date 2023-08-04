<?php

namespace TheliaGiftCard\Controller\Back;

use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

use OpenApi\Annotations as OA;
use Symfony\Component\Routing\Annotation\Route;

use TheliaGiftCard\Service\GiftCardEmailService;

/**
 * Class GiftCardCustomerEmailController
 * @Route("/admin/module/theliagiftcard/giftcard", name="gift_card_mail")
 */
class GiftCardCustomerEmailController extends BaseAdminController
{
    /**
     * @Route("/send", name="send_gift_card_mail", methods="POST")
     * @throws Exception
     */
    public function createOrUpdateAction(
        SecurityContext      $securityContext,
        ParserContext        $parser,
        MailerFactory        $mailer,
        GiftCardEmailService $giftCardEmailService
    ): RedirectResponse|Response|null
    {
        if (null === $securityContext->hasAdminUser()) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
        }

        $form = $this->createForm('gift_card_customer_email');

        try {
            $validatedForm = $this->validateForm($form);
            $data = $validatedForm->getData();

            $pdf = $giftCardEmailService->generatePdfAction($data['gift_card_code']);

            $message = $mailer->createSimpleEmailMessage(
                [ConfigQuery::getStoreEmail() => ConfigQuery::getStoreName()],
                [$data["to"] => $data["to"]],
                $data["email_subject"],
                $giftCardEmailService->generateGiftCardEmailHtmlContent(false, $data),
                "",
            );

            $message->attach($pdf, $data['gift_card_code'].".pdf",'application/pdf');

            $mailer->send($message);

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $error) {
            $messageError = $error->getMessage();

            $form->setErrorMessage($messageError);
            $parser
                ->addForm($form)
                ->setGeneralError($messageError);
        }

        return $this->generateErrorRedirect($form);
    }
}