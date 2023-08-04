<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook;

use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Tools\URL;
use TheliaGiftCard\TheliaGiftCard;

class HookManager extends BaseHook
{
    /*
    * @var SecurityContext
    */
    private SecurityContext $securityContext;

    public function __construct( SecurityContext $securityContext)
    {
        parent::__construct();
        $this->securityContext = $securityContext;
    }

    public function cardGiftAccountUsageInOrder(HookRenderEvent $event): void
    {
        $event->add(
            $this->render("gift-card-usage-on-order.html", [ 'order_id' => $event->getArgument('order_id') ])
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

        if($isGranted) {
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
