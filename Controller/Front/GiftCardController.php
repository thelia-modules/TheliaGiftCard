<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\DefaultActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\URL;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardService;
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
    public function activateGiftCardAction(Request $request, ParserContext $parserContext)
    {
        $this->checkAuth();

        $form = $this->createForm('activate.gift.card.to.customer');

        try {
            $codeForm = $this->validateForm($form);
            $code = $codeForm->get('code_gift_card')->getData();

            $giftCard = GiftCardQuery::create()
                ->filterByCode($code)
                ->filterByBeneficiaryCustomerId(null, Criteria::ISNULL)
                ->findOne();

            if (null === $giftCard) {
                $url = URL::getInstance()->absoluteUrl('/contact');

                //TODO : Ajouter intl

                throw new FormValidationException(Translator::getInstance()->trans("Code incorrecte ou  non activé"));
            }

            $currentCustomerId = $request->getSession()->getCustomerUser()->getId();

            if (null === $giftCard) {
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

        } catch (\Exception $e) {
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

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form);
        }
    }

    /**
     * @Route("/spend", name="spend_gift_card", methods="POST") 
     */
    public function spendGiftCardAction(EventDispatcherInterface $dispatcher, Session $session, ParserContext $parserContext, GiftCardService $giftCardService)
    {
        $this->checkAuth();

        $form = $this->createForm('consume.gift.card');

        try {
            $amountForm = $this->validateForm($form);

            //TODO: a mettre en configuration, en l'etat, aucun cumule avec les coupons
            $dispatcher->dispatch(new DefaultActionEvent(),TheliaEvents::COUPON_CLEAR_ALL);

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