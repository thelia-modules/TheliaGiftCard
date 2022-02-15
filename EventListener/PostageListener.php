<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use TheliaGiftCard\TheliaGiftCard;

class PostageListener implements EventSubscriberInterface
{
    /**
     * @var Request
     */
    private $request;

    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function setPostageInSession(OrderEvent $event)
    {
        $this->request->getSession()->set(TheliaGiftCard::GIFT_CARD_SESSION_POSTAGE, $event->getPostage());
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_SET_POSTAGE => ['setPostageInSession', 2],
        ];
    }
}