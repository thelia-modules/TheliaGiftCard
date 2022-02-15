<?php


namespace TheliaGiftCard\Controller\Back;


use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\SecurityContext;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\URL;
use TheliaGiftCard\Model\GiftCardEmailStatus;
use TheliaGiftCard\Model\GiftCardEmailStatusQuery;
use TheliaGiftCard\TheliaGiftCard;

use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardCustomerEmailController
 * @Route("/admin/module/theliagiftcard/config/customer-email", name="gift_card_mail")
 */
class GiftCardCustomerEmailController extends BaseAdminController
{
    protected function checkAdmin(SecurityContext $securityContext)
    {
        return $test = $securityContext->hasAdminUser();
    }

    /**
     * @Route("/save", name="save_mail")
     */
    public function createOrUpdateAction(SecurityContext $securityContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
        }

        $form = $this->createForm('customer.email.gift.card', FormType::class, [], ['csrf_protection' => false]);

        try {
            $validatedForm = $this->validateForm($form);

            $data = $validatedForm->getData();

            if (null === $giftCardEmailStatus = GiftCardEmailStatusQuery::create()->findOneByStatusId($data['status_id']))  {
                $giftCardEmailStatus = new GiftCardEmailStatus();
            }

            $giftCardEmailStatus->setStatusId($data['status_id']);

            if (isset($data['status_id']) && 'ORDER_CREATED' === $data['status_id']) {
                if (null === $giftCardEmailStatus = GiftCardEmailStatusQuery::create()->findOneBySpecialStatus($data['status_id'])) {
                    $giftCardEmailStatus = new GiftCardEmailStatus();
                }
                $giftCardEmailStatus->setSpecialStatus($data['status_id']);
            }

            $giftCardEmailStatus->setEmailSubject($data['email_subject']);
            $giftCardEmailStatus->setEmailText($data['email_text']);
            $giftCardEmailStatus->save();
        } catch (FormValidationException $error_message) {
            throw new \Exception($error_message->getMessage());
            /*$error_message = $error_message->getMessage();
            $form->setErrorMessage($error_message);
            $this->getParserContext()
                ->addForm($form)
                ->setGeneralError($error_message);*/
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($form->getSuccessUrl()));
    }

    /**
     * @Route("/get", name="get_mail")
     */
    public function getEmailDataForStatus(Request $request)
    {
        if (null === $giftCardEmailData = GiftCardEmailStatusQuery::create()->findOneByStatusId($request->get('status_id'))) {
            $giftCardEmailData = GiftCardEmailStatusQuery::create()->findOneBySpecialStatus($request->get('status_id'));
        }

        return new JsonResponse([
            'id'            => $giftCardEmailData ? $giftCardEmailData->getId() : null,
            'email_subject' => $giftCardEmailData ? $giftCardEmailData->getEmailSubject() : null,
            'email_text'    => $giftCardEmailData ? $giftCardEmailData->getEmailText() : null
        ]);
    }
}