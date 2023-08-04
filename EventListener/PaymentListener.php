<?php

namespace TheliaGiftCard\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Cart;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class PaymentListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack,
        protected GiftCardService $giftCardService
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_PAY_GET_TOTAL => ['handleGiftCard', 60],
            TheliaEvents::MODULE_PAYMENT_IS_VALID => ['forceGiftCardPayment', 200],
        ];
    }

    public function forceGiftCardPayment(IsValidPaymentEvent $event): void
    {
        if ($event->getModule()->getCode() !== TheliaGiftCard::MODULE_CODE) {
            if($this->giftCardService->isGiftCardPayment()){
                $event->stopPropagation();
            }
        }
    }

    public function handleGiftCard(OrderPayTotalEvent $event): void
    {
        /** @var Cart $cart */
        $cart = $this->requestStack->getCurrentRequest()->getSession()->getSessionCart();

        if ($event->getTotal() > $totalGiftCardAmount = TheliaGiftCard::getTotalCartGiftCardAmount($cart->getId())) {
            $event->setTotal($event->getTotal() - $totalGiftCardAmount);
        }
    }
}