<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class GiftCardController
 */
class GiftCardCartController extends BaseFrontController
{
    /**
     * Thelia 3 serves the cart from the checkout controller; the Thelia 2 `cart.view` route
     * belonged to the Front module, which Thelia 3 dropped.
     */
    private const CART_ROUTE = 'checkout_cart';

    #[Route('/gift-card/info/save', name: 'buy_gift_card', methods: 'POST')]
    public function saveInfoAction(
        Session $session,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $urlGenerator
    ): RedirectResponse|Response
    {
        $form = $this->createForm('save_gift_card_info');

        try {
            $this->validateForm($form);

            $cart = $session->getSessionCart($dispatcher);
            $cartEvent = new CartEvent($cart);

            $product_id = $form->getForm()->get('product_id')->getData();
            $sponsorName = $form->getForm()->get('sponsor_name')->getData();
            $beneficiaryName = $form->getForm()->get('beneficiary_name')->getData();
            $beneficiaryAddress = $form->getForm()->get('beneficiary_address')->getData();
            $beneficiaryMessage = $form->getForm()->get('beneficiary_message')->getData();
            $beneficiaryEmail = $form->getForm()->get('beneficiary_email')->getData();

            if ($product_id) {
                $cartEvent->setQuantity(1);
                $cartEvent->setProductId($product_id);
                $cartEvent->setNewness(1);

                $pse = ProductSaleElementsQuery::create()->findOneByProductId($product_id);
                $cartEvent->setProductSaleElementsId($pse->getId());

                $dispatcher->dispatch($cartEvent, TheliaEvents::CART_ADDITEM);

                $infoGiftCard = new GiftCardInfoCart();

                $infoGiftCard->setCartId($cart->getId());

                $currentCartItem = $cartEvent->getCartItem()->getId();

                $infoGiftCard->setCartItemId($currentCartItem);

                if ($sponsorName) {
                    $infoGiftCard->setSponsorName($sponsorName);
                }

                if ($beneficiaryName) {
                    $infoGiftCard->setBeneficiaryName($beneficiaryName);
                }

                if ($beneficiaryMessage) {
                    $infoGiftCard->setBeneficiaryMessage($beneficiaryMessage);
                }

                if ($beneficiaryAddress) {
                    $infoGiftCard->setBeneficiaryAddress($beneficiaryAddress);
                }

                if ($beneficiaryEmail) {
                    $infoGiftCard->setBeneficiaryEmail($beneficiaryEmail);
                }

                $infoGiftCard->save();
            }

        } catch (Exception $e) {
            Tlog::getInstance()->addError($e->getMessage());

            return $this->generateRedirect($urlGenerator->generate(self::CART_ROUTE));
        }

        return $this->generateRedirect($urlGenerator->generate(self::CART_ROUTE));
    }
}
