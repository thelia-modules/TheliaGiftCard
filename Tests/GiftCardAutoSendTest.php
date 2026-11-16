<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\Product;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Core\Event\TheliaEvents;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The automatic mode: the cards created when the order reaches the configured status are active
 * and their code is sent by e-mail. No mail leaves: the test environment uses the null transport,
 * whose messages the mailer logger keeps.
 */
final class GiftCardAutoSendTest extends GiftCardTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigQuery::write('store_name', 'Test shop', false, true);
        ConfigQuery::write('store_email', 'shop@example.com', false, true);
    }

    public function testEveryCardOfTheOrderIsActiveAndSentToTheBuyer(): void
    {
        $order = $this->orderOfCards(2);

        $this->reachTheStatus($order);

        $cards = $this->cardsOf($order);
        self::assertCount(2, $cards);
        $emails = $this->sentEmails();
        self::assertCount(2, $emails);
        $sentFiles = [];
        foreach ($emails as $email) {
            self::assertSame($order->getCustomer()->getEmail(), $email->getTo()[0]->getAddress());
            $sentFiles[] = $email->getAttachments()[0]->getFilename();
        }
        foreach ($cards as $card) {
            self::assertSame(1, $card->getStatus());
            self::assertContains($card->getCode().'.pdf', $sentFiles);
        }
    }

    public function testTheRecipientTypedAtThePurchaseReceivesTheCodeInsteadOfTheBuyer(): void
    {
        // One line of two cards with a recipient, one line of a card without.
        $order = $this->orderOfCards(2);
        $this->orderLine($order, $this->product, 1, 50.0);
        $this->beneficiaryOfTheFirstLine($order, 'bob@example.com');

        $this->reachTheStatus($order);

        $recipients = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $this->sentEmails());
        sort($recipients);
        $expected = ['bob@example.com', 'bob@example.com', $order->getCustomer()->getEmail()];
        sort($expected);
        self::assertSame($expected, $recipients, 'Every card of a line goes to the recipient of that line, the cards of a line without one to the buyer.');
    }

    public function testTheEmailIsInTheLanguageOfTheOrderWhateverTheAdministratorLanguage(): void
    {
        $order = $this->orderOfCards(1);
        $order->setLangId($this->lang('de_DE')->getId())->save();
        $this->currentSession()->set('thelia.current.admin_lang', $this->lang('fr_FR'));

        $this->reachTheStatus($order);

        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertStringContainsString('Geschenkkarte', (string) $emails[0]->getSubject());
    }

    public function testTheStatusIsWrittenBeforeTheCardsAreCreatedAndTheCodesSentByTheRealDispatch(): void
    {
        $order = $this->orderOfCards(1);
        self::assertNotSame($this->paidStatus()->getId(), $order->getStatusId());

        $this->reachTheStatus($order);

        self::assertSame($this->paidStatus()->getId(), OrderQuery::create()->findPk($order->getId())?->getStatusId());
        self::assertCount(1, $this->cardsOf($order));
        self::assertCount(1, $this->sentEmails());
    }

    public function testNoCodeLeavesWhenAnotherListenerOfTheStatusChangeFails(): void
    {
        $order = $this->orderOfCards(2);
        // Below every listener of the core: the caller rolls its transaction back on this failure.
        static::getContainer()->get('event_dispatcher')->addListener(TheliaEvents::ORDER_UPDATE_STATUS, static function (): void {
            throw new \RuntimeException('A later listener fails.');
        }, -5);

        try {
            $this->reachTheStatus($order);
            self::fail('The failure of the listener goes up to the caller.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->sentEmails(), 'The cards may be rolled back: their codes must not have left.');
    }

    public function testWithoutRequestNorSessionTheEmailAndItsPdfAreInTheLanguageOfTheOrder(): void
    {
        $order = $this->orderOfCards(1);
        $order->setLangId($this->lang('de_DE')->getId())->save();
        // A command marking an order as paid: nothing on the stack.
        while (null !== $this->requestStack()->pop()) {
        }

        $this->reachTheStatus($order);

        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertStringContainsString('Geschenkkarte', (string) $emails[0]->getSubject());
        self::assertStringStartsWith('%PDF', $emails[0]->getAttachments()[0]->getBody());
    }

    public function testGoingThroughTheStatusAgainSendsNothingMore(): void
    {
        $order = $this->orderOfCards(1);

        $this->reachTheStatus($order);
        $this->reachTheStatus($order);

        self::assertCount(1, $this->sentEmails());
        self::assertCount(1, $this->cardsOf($order));
    }

    public function testAMailThatFailsNeverStopsTheStatusNorTheOtherCards(): void
    {
        // The line of the first two cards names a recipient that cannot be written to; the other line does not.
        $order = $this->orderOfCards(2);
        $this->orderLine($order, $this->product, 1, 50.0);
        $this->beneficiaryOfTheFirstLine($order, 'not an address');

        $this->reachTheStatus($order);

        $cards = $this->cardsOf($order);
        self::assertCount(3, $cards);
        foreach ($cards as $card) {
            self::assertSame(1, $card->getStatus(), 'The card stays active, sendable by hand.');
        }
        self::assertSame($this->paidStatus()->getId(), OrderQuery::create()->findPk($order->getId())?->getStatusId(), 'The status is changed.');
        $emails = $this->sentEmails();
        self::assertCount(1, $emails, 'The failed sends do not stop the card of the other line.');
        self::assertSame($order->getCustomer()->getEmail(), $emails[0]->getTo()[0]->getAddress());
    }

    public function testWithTheModeOffCardsAreInactiveAndNothingIsSent(): void
    {
        $order = $this->orderOfCards(2, '0');

        $this->reachTheStatus($order);

        $cards = $this->cardsOf($order);
        self::assertCount(2, $cards);
        foreach ($cards as $card) {
            self::assertSame(0, $card->getStatus());
        }
        self::assertSame([], $this->sentEmails());
    }

    private function orderOfCards(int $quantity, string $mode = '1'): Order
    {
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, $mode, false, true);
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_ORDER_STATUS_CONF_NAME, (string) $this->paidStatus()->getId(), false, true);
        $this->product = $this->giftCardProduct(50);
        $this->orderLine($order, $this->product, $quantity, 50.0);

        return $order;
    }

    private function beneficiaryOfTheFirstLine(Order $order, string $email): void
    {
        $orderProduct = OrderProductQuery::create()->filterByOrderId($order->getId())->orderById()->findOne();
        self::assertNotNull($orderProduct);

        (new GiftCardInfoCart())
            ->setOrderProductId($orderProduct->getId())
            ->setSponsorName('Alice')
            ->setBeneficiaryName('Bob')
            ->setBeneficiaryEmail($email)
            ->save();
    }

    /**
     * The status change as the core does it: the event goes through the dispatcher of the container,
     * the order is not yet at the status.
     */
    private function reachTheStatus(Order $order): void
    {
        $event = new OrderEvent($order);
        $event->setStatus($this->paidStatus()->getId());

        static::getContainer()->get('event_dispatcher')->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    private function paidStatus(): OrderStatus
    {
        $status = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID);
        self::assertNotNull($status);

        return $status;
    }

    /**
     * @return list<\TheliaGiftCard\Model\GiftCard>
     */
    private function cardsOf(Order $order): array
    {
        return array_values(GiftCardQuery::create()->filterByOrderId($order->getId())->find()->getData());
    }

    /**
     * The e-mails that carry a card, as a PDF. A shop sends other mails when an order is paid (the
     * payment confirmation of its payment module), which are not the business of this test.
     *
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn (object $message): bool => $message instanceof Email && [] !== array_filter(
                $message->getAttachments(),
                static fn (DataPart $part): bool => 'application/pdf' === $part->getContentType(),
            ),
        ));
    }

    private function currentSession(): \Thelia\Core\HttpFoundation\Session\Session
    {
        $session = $this->requestStack()->getCurrentRequest()?->getSession();
        self::assertInstanceOf(\Thelia\Core\HttpFoundation\Session\Session::class, $session);

        return $session;
    }
}
