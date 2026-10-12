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
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\TheliaGiftCard;
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
        UrlGeneratorInterface $urlGenerator,
        Translator $translator,
        ParserContext $parserContext
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
                // The posted id is the customer's: only a product of the gift card category may be
                // put on the cart with the card information.
                $pse = $this->isGiftCardProductId($product_id)
                    ? ProductSaleElementsQuery::create()->findOneByProductId((int) $product_id)
                    : null;

                if (null === $pse) {
                    throw new FormValidationException($translator->trans('This product is not a gift card.', [], TheliaGiftCard::DOMAIN_NAME));
                }

                $cartEvent->setQuantity(1);
                $cartEvent->setProductId((int) $product_id);
                $cartEvent->setNewness(1);
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

        } catch (FormValidationException $e) {
            $form->setErrorMessage($e->getMessage());

            $parserContext
                ->addForm($form)
                ->setGeneralError($e->getMessage());

            return $this->generateErrorRedirect($form) ?? $this->generateRedirect($urlGenerator->generate(self::CART_ROUTE));
        } catch (Exception $e) {
            Tlog::getInstance()->addError($e->getMessage());

            return $this->generateRedirect($urlGenerator->generate(self::CART_ROUTE));
        }

        return $this->generateRedirect($urlGenerator->generate(self::CART_ROUTE));
    }

    private function isGiftCardProductId(mixed $productId): bool
    {
        return (\is_int($productId) || (\is_string($productId) && ctype_digit($productId)))
            && \in_array((int) $productId, TheliaGiftCard::getGiftCardProductList(), true);
    }
}
