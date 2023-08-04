<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Model\CategoryQuery;
use Thelia\Tools\URL;
use TheliaGiftCard\TheliaGiftCard;

class HookFrontManager extends BaseHook
{
    public function onAccountBottom(HookRenderEvent $event): void
    {
        $category = CategoryQuery::create()->findPk(TheliaGiftCard::getGiftCardCategoryId());
        if ($category) {
            $urlToBuyGiftCard = URL::getInstance()->absoluteUrl($category->getRewrittenUrl($this->getSession()->getLang()->getLocale()));

            $event->add(
                $this->render("account-gift-card.html", ['urlToBuyGiftCard' => $urlToBuyGiftCard])
            );
        }
    }

    public function onOrderInvoiceBottom(HookRenderEvent $event): void
    {
        $event->add(
            $this->render("order-invoice-gift-card.html", ['total_without_giftcard' => $event->getArgument('total')])
        );
    }

    public function onProductAdditional(HookRenderEvent $event): void
    {
        $productId = $event->getArgument('product');

        $tabProductGiftCard = TheliaGiftCard::getGiftCardProductList();

        if (in_array($productId, $tabProductGiftCard)) {
            $event->add(
                $this->render("product-additional-gift-card.html", ['product_id' => $productId])
            );
        }
    }
}
