<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

class HookManager extends BaseHook
{
    public function __construct(
        private readonly SecurityContext $securityContext,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.top-menu-tools' => [
                ['type' => 'back', 'method' => 'onMainTopMenuTools'],
            ],
            'order-edit.after-order-product-list' => [
                ['type' => 'back', 'method' => 'cardGiftAccountUsageInOrder'],
            ],
        ];
    }

    public function cardGiftAccountUsageInOrder(HookRenderEvent $event): void
    {
        $orderId = (int) $event->getArgument('order_id');

        $order = OrderQuery::create()->findPk($orderId);
        $currencySymbol = $order?->getCurrency()?->getSymbol() ?? '';

        $lines = [];
        $totalSpendAmount = 0.0;

        $giftCardOrders = GiftCardOrderQuery::create()
            ->filterByOrderId($orderId)
            ->find();

        foreach ($giftCardOrders as $giftCardOrder) {
            $spendAmount = (float) $giftCardOrder->getSpendAmount();
            $totalSpendAmount += $spendAmount;

            $giftCard = GiftCardQuery::create()->findPk($giftCardOrder->getGiftCardId());

            $lines[] = [
                'code' => $giftCard?->getCode() ?? '',
                'spend_amount' => $spendAmount,
            ];
        }

        $event->add(
            $this->render(
                'TheliaGiftCard/gift-card-usage-on-order.html.twig',
                [
                    'lines' => $lines,
                    'total_spend_amount' => $totalSpendAmount,
                    'currency_symbol' => $currencySymbol,
                ]
            )
        );
    }

    public function onMainTopMenuTools(HookRenderBlockEvent $event): void
    {
        $isGranted = $this->securityContext->isGranted(
            ["ADMIN"],
            ["admin.orders.lines.export"],
            [TheliaGiftCard::getModuleCode()],
            [AccessManager::VIEW]
        );

        if ($isGranted) {
            $event->add(
                [
                    'id' => 'tools_menu_gidt_card',
                    'class' => '',
                    'url' => URL::getInstance()->absoluteUrl('/admin/module/TheliaGiftCard'),
                    'title' => $this->trans('Gift Card Config', [], TheliaGiftCard::DOMAIN_NAME)
                ]
            );
        }
    }
}
