<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Exception;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use TheliaGiftCard\Form\ActivateGiftCardToCustomerForm;
use TheliaGiftCard\Form\ConsumeGiftCart;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

#[Route('/gift-card', name: 'gift_card_')]
class GiftCardController extends BaseFrontController
{
    #[Route('/activate-code', name: 'activate', methods: 'POST')]
    public function activateGiftCardAction(
        ParserContext $parserContext,
        RequestStack $requestStack,
        Translator $translator
    )
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

                //TODO : Ajouter intl

                throw new FormValidationException(Translator::getInstance()->trans("Code incorrecte ou  non activé"));
            }

            $currentCustomerId = $requestStack->getCurrentRequest()->getSession()->getCustomerUser()->getId();

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

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }
    }

    /**
     * @throws Exception
     */
    #[Route('/spend', name: 'spend', methods: 'POST')]
    public function spendGiftCardAction(
        EventDispatcherInterface $dispatcher,
        ParserContext $parserContext,
        GiftCardService $giftCardService,
        Session $session
    ): RedirectResponse|Response|null
    {
        $this->checkAuth();

        $form = $this->createForm(ConsumeGiftCart::getName());

        try {
            $amountForm = $this->validateForm($form);

            //TODO: a mettre en configuration, en l'etat, aucun cumule avec les coupons
            $dispatcher->dispatch(TheliaEvents::COUPON_CLEAR_ALL);

            $amount = $amountForm->get('amount_used')->getData();
            $codes = $amountForm->get('gift_card_code')->getData();

            $customer = $session->getCustomerUser();
            $cart = $session->getSessionCart();
            $order = $session->getOrder();

            if (null == $customer) {
                return $this->generateRedirectFromRoute('order.invoice');
            }

            $restAmount = 0;
            foreach ($codes as $code) {
                if ($restAmount > 0) {
                    $amount = $amount + $restAmount;
                }

                $restAmount = $giftCardService->spendGiftCard($code, $amount, $cart, $order, $customer);
            }

            return $this->generateSuccessRedirect($form);

        } catch (FormValidationException $error_message) {

            $form->setErrorMessage($error_message);

            $parserContext
                ->addForm($form)
                ->setGeneralError($error_message);

            return $this->generateErrorRedirect($form);
        }
    }
}