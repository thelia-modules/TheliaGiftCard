<?php

declare(strict_types=1);

namespace TheliaGiftCard\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Order;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Service\GiftCardAmount;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class PaymentListener implements EventSubscriberInterface
{
    public function __construct(
        protected GiftCardService $giftCardService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_PAY_GET_TOTAL => ['handleGiftCard', 60],
            TheliaEvents::MODULE_PAYMENT_IS_VALID => ['forceGiftCardPayment', 200],
        ];
    }

    /**
     * Cards that pay for the whole cart leave the gift card module as the only valid one.
     */
    public function forceGiftCardPayment(IsValidPaymentEvent $event): void
    {
        if (TheliaGiftCard::MODULE_CODE !== $event->getModule()->getCode()
            && $this->giftCardService->isGiftCardPayment($event->getCart())) {
            $event->stopPropagation();
        }
    }

    /**
     * The total a payment module asks for, less what the gift cards pay.
     *
     * What they pay is what was debited from them for this order, or, before the debit, what
     * was put on the cart the order comes from — never more than the total: the card is only
     * debited of what the payment deducts. The previous releases read the session cart, and
     * deducted nothing at all when the cards went beyond the total.
     */
    public function handleGiftCard(OrderPayTotalEvent $event): void
    {
        $total = GiftCardAmount::cents($event->getTotal());
        $paidWithGiftCards = min($total, $this->paidWithGiftCards($event->getOrder()));

        if ($paidWithGiftCards > 0) {
            $event->setTotal(($total - $paidWithGiftCards) / 100);
        }
    }

    private function paidWithGiftCards(Order $order): int
    {
        if (null !== $order->getId()) {
            $debited = GiftCardOrderQuery::create()->filterByOrderId($order->getId())->find();

            if (0 < \count($debited)) {
                $cents = 0;
                /** @var GiftCardOrder $giftCardOrder */
                foreach ($debited as $giftCardOrder) {
                    $cents += GiftCardAmount::cents($giftCardOrder->getSpendAmount());
                }

                return $cents;
            }
        }

        if (null === $order->getCartId()) {
            return 0;
        }

        $cents = 0;
        foreach (GiftCardCartQuery::create()->filterByCartId($order->getCartId())->find() as $giftCardCart) {
            $cents += GiftCardAmount::cents($giftCardCart->getSpendAmount());
        }

        return $cents;
    }
}
