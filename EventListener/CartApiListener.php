<?php

namespace TheliaGiftCard\EventListener;

use OpenApi\Events\ModelExtendDataEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TheliaGiftCard\TheliaGiftCard;

class CartApiListener implements EventSubscriberInterface
{
    public function updateCartWithGiftCard(ModelExtendDataEvent $event): void
    {
        $event->setExtendDataKeyValue("total_amount_gift_card", TheliaGiftCard::getTotalCartGiftCardAmount($event->getModel()->getId()));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ModelExtendDataEvent::ADD_EXTEND_DATA_PREFIX . "cart" => ['updateCartWithGiftCard', 10],
        ];
    }
}