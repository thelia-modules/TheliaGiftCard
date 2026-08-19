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
    public static function getSubscribedHooks(): array
    {
        return [
            'account.bottom' => [
                ['type' => 'front', 'method' => 'onAccountBottom'],
            ],
            'product.bottom' => [
                ['type' => 'front', 'method' => 'onProductAdditional'],
            ],
            'order-invoice.giftcard-form' => [
                ['type' => 'front', 'method' => 'onOrderInvoiceBottom'],
            ],
        ];
    }

    public function onAccountBottom(HookRenderEvent $event): void
    {
        $category = CategoryQuery::create()->findPk(TheliaGiftCard::getGiftCardCategoryId());
        if ($category) {
            $request = $this->getRequest();
            $locale = ($request && $request->hasSession())
                ? $request->getSession()->getLang()->getLocale()
                : (\Thelia\Model\LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US');
            $urlToBuyGiftCard = URL::getInstance()->absoluteUrl($category->getRewrittenUrl($locale));

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
