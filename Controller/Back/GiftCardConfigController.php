<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Back;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;
use TheliaGiftCard\Model\GiftCardInfoCart;
use Symfony\Component\Routing\Annotation\Route;


/**
 * Class GiftCardConfigController
 * @Route("/admin/module/theliagiftcard", name="gift_card_config") 
 */
class GiftCardConfigController extends BaseAdminController
{
    /**
     * @Route("/config/save", name="edit_config") 
     */
    public function editConfigAction(SecurityContext $securityContext, ParserContext $parserContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $form = $this->createForm('config.card.gift');

        try {
            $configForm = $this->validateForm($form);

            $categoryId = $configForm->get('gift_card_category')->getData();
            $orderStatusId = $configForm->get('gift_card_paid_status')->getData();

            ConfigQuery::write(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, $categoryId, false, true);
            ConfigQuery::write(TheliaGiftCard::GIFT_CARD_ORDER_STATUS_CONF_NAME, $orderStatusId, false, true);
        } catch (FormValidationException $error_message) {
            $error_message = $error_message->getMessage();
            $form->setErrorMessage($error_message);
            $parserContext
                ->addForm($form)
                ->setGeneralError($error_message);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($form->getSuccessUrl()));
    }

    /**
     * @Route("/config/send/pdf", name="config_send_pdf") 
     */
    public function generatePdfAction(
        Request $request,
        TemplateHelperInterface $templateHelper,
        EventDispatcherInterface $dispatcher,
        SecurityContext $securityContext,
        GiftCardService $giftCardService
    )
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $code = $request->query->get('code');
        $locale = $request->query->get('l');

        $infos = $giftCardService->getInfoGiftCard($code);

        if (false === $infos) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
        }

        $html = $this->renderRaw(
            'giftCard',
            array(
                'message' => $infos['message'],
                'code' => $infos['code'],
                'SNAME' => $infos['sponsorName'],
                'BNAME' => $infos['beneficiaryName'],
                'AMOUNT' => $infos['amount'],
                'default_locale' => $locale
            ),
            $templateHelper->getActivePdfTemplate()
        );

        $pdfEvent = new PdfEvent($html);

        $dispatcher->dispatch($pdfEvent, TheliaEvents::GENERATE_PDF);

        if ($pdfEvent->hasPdf()) {
            return $this->pdfResponse($pdfEvent->getPdf(), 'gift_card', 200, true);
        }
    }

    /**
     * @Route("/generate-gift-card", name="generate_gift_card") 
     */
    public function generateGiftCardAction(ParserContext $parserContext, SecurityContext $securityContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $form = $this->createForm('manualy.create.card.gift');

        $giftCardForm = $this->validateForm($form);

        try {
            $expirationDate = $giftCardForm->get('expiration_date')->getData();

            $newGiftCard = new GiftCard();
            $newGiftCard
                ->setCode(TheliaGiftCard::GENERATE_CODE())
                ->setAmount($giftCardForm->get('amount')->getData())
                ->setExpirationDate($expirationDate->format('Y-m-d'))
                ->setStatus(1)
                ->setSpendAmount(0)
                ->save();

            $giftCardInfo = new GiftCardInfoCart();

            $giftCardInfo
                ->setGiftCardId($newGiftCard->getId())
                ->setBeneficiaryName($giftCardForm->get('beneficiary_name')->getData())
                ->setSponsorName($giftCardForm->get('sponsor_name')->getData())
                ->setBeneficiaryMessage($giftCardForm->get('beneficiary_message')->getData())
                ->save();

        } catch (FormValidationException $error_message) {

            $error_message = $error_message->getMessage();
            $form->setErrorMessage($error_message);
            $parserContext
                ->addForm($form)
                ->setGeneralError($error_message);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($form->getSuccessUrl()));

    }

    /**
     * @Route("/activate", name="activate_gift_card") 
     */
    public function activateGiftCardAction(Request $request, SecurityContext $securityContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $codeGC = $request->get('code');

        $giftCard = GiftCardQuery::create()
            ->filterByCode($codeGC)
            ->filterByStatus(0)
            ->findOne();

        if ($giftCard) {
            $giftCard->setStatus(1)
                ->save();
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
    }

    /**
     * @Route("/edit-gift-card", name="edit_gift_card") 
     */
    public function editGiftCardAction(ParserContext $parserContext, SecurityContext $securityContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $form = $this->createForm('manualy.edit.gift.card');

        $giftCardForm = $this->validateForm($form);

        try {
            $giftCardId = $giftCardForm->get('gift_card_id')->getData();
            $expirationDate = $giftCardForm->get('expiration_date')->getData();
            $amount = $giftCardForm->get('amount')->getData();

            $currentGiftCard = GiftCardQuery::create()
                ->filterById($giftCardId)
                ->findOne();

            $currentGiftCard
                ->setAmount($giftCardForm->get('amount')->getData())
                ->setExpirationDate($expirationDate->format('Y-m-d'))
                ->save();

        } catch (FormValidationException $error_message) {
            $error_message = $error_message->getMessage();
            $form->setErrorMessage($error_message);
            $parserContext
                ->addForm($form)
                ->setGeneralError($error_message);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($form->getSuccessUrl()));
    }

    /**
     * @Route("/deactivate", name="deactivate_gift_card") 
     */
    public function deactivateGiftCardAction(Request $request, SecurityContext $securityContext)
    {
        if (null === $this->checkAdmin($securityContext)) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/modules'));
        }

        $codeGC = $request->get('code');

        $giftCard = GiftCardQuery::create()
            ->filterByCode($codeGC)
            ->filterByStatus(1)
            ->findOne();

        if ($giftCard) {
            $giftCard->setStatus(0)
                ->save();
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
    }

    protected function checkAdmin(SecurityContext $securityContext)
    {
        return $test = $securityContext->hasAdminUser();
    }
}
