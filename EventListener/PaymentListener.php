<?php

declare(strict_types=1);

namespace TheliaGiftCard\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\OrderPaymentPartQuery;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Service\GiftCardAmount;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class PaymentListener implements EventSubscriberInterface
{
    /**
     * After the core, which sets the total of the order at 128 and which this listener subtracts from. The
     * listener of the payment parts of the core, at 16, replaces the total with the balance of an order
     * that has parts and does nothing for one that has none; this one does the opposite, so that the
     * two never act on the same order and their relative order does not matter. 60 is the priority the
     * previous releases used, which the core documents.
     */
    public const TOTAL_PRIORITY = 60;

    public function __construct(
        protected GiftCardService $giftCardService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_PAY_GET_TOTAL => ['deductWhatWasDebitedBeforePaymentParts', self::TOTAL_PRIORITY],
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
     * Transition, to remove in a later version: the total a payment module asks for, less what was
     * debited from gift cards for an order placed with the previous releases.
     *
     * Those releases debited the cards on ORDER_BEFORE_PAYMENT and wrote no payment part: an order
     * placed then and not paid yet is still paid by a module that asks ORDER_PAY_GET_TOTAL for the
     * total, and the cards that were debited, which the buyer may have since taken off the cart, are
     * not asked again. An order with payment parts is none of this listener's business: the core
     * answers with the balance of its provider, and a deduction here would be a second one. Never below
     * nothing.
     */
    public function deductWhatWasDebitedBeforePaymentParts(OrderPayTotalEvent $event): void
    {
        $orderId = $event->getOrder()->getId();

        if (null === $orderId || OrderPaymentPartQuery::create()->filterByOrderId($orderId)->exists()) {
            return;
        }

        $debited = 0;

        foreach (GiftCardOrderQuery::create()->filterByOrderId($orderId)->filterBySpent()->find() as $giftCardOrder) {
            $spendAmount = (string) ($giftCardOrder->getSpendAmount() ?? '0');

            // A negative row is not money that was debited: it must not make ORDER_PAY_GET_TOTAL fail.
            if (str_starts_with(trim($spendAmount), '-')) {
                Tlog::getInstance()->warning(\sprintf('TheliaGiftCard: gift_card_order %d has a negative spend_amount, ignored in the total of the order.', $giftCardOrder->getId()));

                continue;
            }

            $debited += GiftCardAmount::exactCents($spendAmount);
        }

        if ($debited <= 0) {
            return;
        }

        $total = GiftCardAmount::cents($event->getTotal());

        $event->setTotal(GiftCardAmount::leftToPay($total, $debited) / 100);
    }
}
