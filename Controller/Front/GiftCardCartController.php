<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Controller\Front;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class GiftCardController
 * @Route("/gift-card/info", name="gift_card_info")
 */
class GiftCardCartController extends BaseFrontController
{
    /**
     * @Route("/save", name="save_info") 
     */
    public function saveInfoAction(Session $session, EventDispatcherInterface $dispatcher)
    {
        $form = $this->createForm('save.gift.card.info');

        try {
            $this->validateForm($form);

            $cart = $session->getSessionCart($dispatcher);
            $cartEvent = new CartEvent($cart);

            $product_id = $form->getForm()->get('product_id')->getData();
            $sponsorName = $form->getForm()->get('sponsor_name')->getData();
            $beneficiaryName = $form->getForm()->get('beneficiary_name')->getData();
            $beneficiaryMessage = $form->getForm()->get('beneficiary_message')->getData();

            if ($product_id) {
                $cartEvent->setQuantity(1);
                $cartEvent->setProduct($product_id);
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

                $infoGiftCard->save();
            }

        } catch (\Exception $e) {
            return $this->generateRedirectFromRoute('cart.view', ['error_custom' => $e]);
        }

        return $this->generateRedirectFromRoute('cart.view');
    }
}
