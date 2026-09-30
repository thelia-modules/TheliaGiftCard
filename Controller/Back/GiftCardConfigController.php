<?php

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Controller\Back;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\TokenProvider;
use Thelia\Tools\URL;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Class GiftCardConfigController.
 */
#[Route('/admin/module/theliagiftcard')]
class GiftCardConfigController extends BaseAdminController
{
    #[Route('/config/save', name: 'gift_card_config')]
    public function editConfigAction(ParserContext $parserContext): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm('gift_card_config');

        try {
            $configForm = $this->validateForm($form);

            $categoryId = $configForm->get('gift_card_category')->getData();
            $orderStatusId = $configForm->get('gift_card_paid_status')->getData();
            $isAutoSend = $configForm->get('gift_card_auto_send')->getData();

            ConfigQuery::write(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, $categoryId, false, true);
            ConfigQuery::write(TheliaGiftCard::GIFT_CARD_ORDER_STATUS_CONF_NAME, $orderStatusId, false, true);
            ConfigQuery::write(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, $isAutoSend, false, true);
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
     * Downloads the PDF of a card, in the language picked in the list (`l`), or else in the
     * back-office language of the administrator.
     */
    #[Route('/config/send/pdf', name: 'config_send_pdf')]
    public function manualSendPdfAction(
        Request $request,
        GiftCardEmailService $giftCardEmailService
    ): RedirectResponse|Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::VIEW)) {
            return $response;
        }

        $code = (string) $request->query->get('code', '');

        try {
            return $this->pdfResponse(
                $giftCardEmailService->generatePdfAction($code, $request->query->get('l')),
                'gift_card',
                200,
                true
            );
        } catch (\Exception $exception) {
            Tlog::getInstance()->error('Gift card PDF not generated: '.$exception->getMessage());

            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
        }
    }

    /**
     * @throws PropelException
     */
    #[Route('/generate-gift-card', name: 'generate_gift_card')]
    public function generateGiftCardAction(ParserContext $parserContext): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm('manualy_create_gift_card');

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
     * @throws PropelException
     */
    #[Route('/activate', name: 'activate_gift_card', methods: ['POST'])]
    public function activateGiftCardAction(Request $request, TokenProvider $tokenProvider): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkToken((string) $request->query->get('_token'));

        $codeGC = $request->query->get('code');

        $giftCard = GiftCardQuery::create()
            ->filterByCode($codeGC)
            ->filterByStatus(0)
            ->findOne();

        $giftCard?->setStatus(1)
            ->save();

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
    }

    /**
     * @throws PropelException
     */
    #[Route('/edit-gift-card', name: 'edit_gift_card')]
    public function editGiftCardAction(ParserContext $parserContext): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm('edit_gift_card');

        $giftCardForm = $this->validateForm($form);

        try {
            $giftCardId = $giftCardForm->get('gift_card_id')->getData();

            $expirationDate = $giftCardForm->get('expiration_date')->getData();
            $amount = $giftCardForm->get('amount')->getData();
            $address = $giftCardForm->get('beneficiary_address')->getData();

            $currentGiftCard = GiftCardQuery::create()
                ->filterById($giftCardId)
                ->findOne();

            $currentGiftCard
                ->setAmount($amount)
                ->setExpirationDate($expirationDate->format('Y-m-d'))
                ->save();

            if ($address) {
                $currentGiftCardInfos = GiftCardInfoCartQuery::create()
                    ->filterByGiftCardId($giftCardId)
                    ->findOneOrCreate();

                $currentGiftCardInfos->setBeneficiaryAddress($address)->save();
            }
        } catch (FormValidationException $error_message) {
            $error_message = $error_message->getMessage();
            $form->setErrorMessage($error_message);
            $parserContext
                ->addForm($form)
                ->setGeneralError($error_message);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl($form->getSuccessUrl()));
    }

    #[Route('/deactivate', name: 'deactivate_gift_card', methods: ['POST'])]
    public function deactivateGiftCard(Request $request, TokenProvider $tokenProvider): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, [TheliaGiftCard::MODULE_CODE], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkToken((string) $request->query->get('_token'));

        try {
            $codeGC = $request->query->get('code');

            $giftCard = GiftCardQuery::create()
                ->filterByCode($codeGC)
                ->filterByStatus(1)
                ->findOne();

            $giftCard?->setStatus(0)->save();
        } catch (PropelException $exception) {
            Tlog::getInstance()->addAlert($exception->getMessage());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'));
    }

}
