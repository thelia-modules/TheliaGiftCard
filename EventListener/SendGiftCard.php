<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\Order;
use TheliaGiftCard\Dto\CreatedGiftCard;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The "automatic" mode: the code of the cards created by a change of status is e-mailed.
 *
 * It runs after every other listener of the change (the core ones stop at priority 5): when one of
 * them fails and the caller rolls its transaction back, no code has left for a card that is gone.
 */
class SendGiftCard implements EventSubscriberInterface
{
    public function __construct(
        protected OrderPayListener $orderPayListener,
        // A locator: the e-mail service is built only when something is sent.
        #[AutowireLocator([GiftCardEmailService::class])]
        protected ContainerInterface $services,
    ) {
    }

    public function onOrderStatusUpdate(OrderEvent $event): void
    {
        // Taken in every mode, so that nothing waits for the end of the dispatch.
        $createdCards = $this->orderPayListener->takeCreatedCards($event);

        if (!TheliaGiftCard::isAutoSendEmail()) {
            return;
        }

        foreach ($createdCards as $createdCard) {
            $this->sendCode($event->getOrder(), $createdCard);
        }
    }

    /**
     * To the recipient typed for the order line of the card, to the buyer when that line has none,
     * in the language of the order. A failure is logged and never stops the change of status: the
     * card stays active and can be sent by hand from the back office.
     */
    private function sendCode(Order $order, CreatedGiftCard $createdCard): void
    {
        $giftCard = $createdCard->giftCard;

        try {
            $recipient = trim((string) GiftCardInfoCartQuery::create()
                ->filterByOrderProductId($createdCard->orderProductId)
                ->findOne()
                ?->getBeneficiaryEmail());

            if ('' === $recipient) {
                $recipient = (string) $order->getCustomer()->getEmail();
            }

            $emailService = $this->services->get(GiftCardEmailService::class);
            \assert($emailService instanceof GiftCardEmailService);

            // The locale is given: without it the language is the one of the administrator in session.
            $emailService->sendByEmail($giftCard->getCode(), $recipient, null, null, $order->getLang()?->getLocale());
        } catch (\Throwable $exception) {
            // The class only: the message of a refused address quotes the address.
            Tlog::getInstance()->addError(\sprintf('Gift card %d of order %d not sent by e-mail (%s).', $giftCard->getId(), $order->getId(), $exception::class));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderStatusUpdate', -10],
        ];
    }
}
