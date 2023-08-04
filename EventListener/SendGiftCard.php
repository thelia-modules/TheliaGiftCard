<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\EventListener;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Mailer\MailerFactory;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\TheliaGiftCard;

class SendGiftCard implements EventSubscriberInterface
{

    public function __construct(
        protected MailerFactory        $mailer,
        protected GiftCardEmailService $giftCardEmailService,
        protected GiftCardService      $giftCardService,
    )
    {
    }

    /**
     * @throws PropelException
     */
    public function onOrderStatusUpdate(OrderEvent $event): void
    {
        if ($event->getOrder()->getStatusId() !== TheliaGiftCard::getGiftCardOrderStatusId()) {
            return;
        }

        $order = $event->getOrder();
        $giftCards = $order->getGiftCards();

        /** @var GiftCard $giftCard */
        foreach ($giftCards as $giftCard) {
            if (!$giftCard->getStatus()) {
                continue;
            }

            /*$infos = $this->giftCardService->getInfoGiftCard($giftCard->getCode());
            $pdf = $this->giftCardEmailService->generatePdfAction($giftCard->getCode());

            if ($infos && isset($infos['beneficiaryEmail']) && !empty($infos['beneficiaryEmail'])) {
                $email = $this->giftCardEmailService->createEmail($pdf, $giftCard, $infos['beneficiaryEmail'], true);
                $this->mailer->send($email);
            } else {
                $customerEmail = $order->getCustomer()->getEmail();
                $email = $this->giftCardEmailService->createEmail($pdf, $giftCard, $customerEmail);
                $this->mailer->send($email);
            }*/
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['onOrderStatusUpdate', 60]
        ];
    }
}