<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Propel\Runtime\ActiveQuery\ModelCriteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use TheliaGiftCard\EventListener\PaymentListener;
use TheliaGiftCard\Loop\GiftCardOrderList;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The total asked by a payment module for an order placed with the previous releases, which debited the
 * cards before the payment and wrote no payment part: the transition listener of ORDER_PAY_GET_TOTAL.
 */
final class GiftCardTransitionTotalTest extends GiftCardTestCase
{
    public function testAnOrderDebitedBeforeTheCardsWereRemovedFromTheCartIsAskedTheTotalLessWhatWasDebited(): void
    {
        $order = $this->orderOf(100.0);
        // The cards left the cart since: only the rows of the debit say what they paid.
        $this->debited($order, '30');
        $this->debited($order, '12.5');

        self::assertSame(57.5, $this->payTotal($order));
    }

    public function testWhatWasDebitedNeverTakesTheTotalBelowNothing(): void
    {
        $order = $this->orderOf(100.0);
        $this->debited($order, '60');
        $this->debited($order, '60');

        self::assertSame(0.0, $this->payTotal($order));
    }

    public function testWhatWasGivenBackToTheCardsIsNotDeducted(): void
    {
        $order = $this->orderOf(100.0);
        $this->debited($order, '30', GiftCardPaymentService::STATUS_GIVEN_BACK);
        $this->debited($order, '10');

        self::assertSame(90.0, $this->payTotal($order));
    }

    public function testAnOrderNothingWasDebitedForKeepsItsTotal(): void
    {
        self::assertSame(100.0, $this->payTotal($this->orderOf(100.0)));
    }

    public function testAnOrderWithPaymentPartsIsLeftToTheCore(): void
    {
        $order = $this->orderOf(100.0);
        $this->debited($order, '30');
        $this->part($order, TheliaGiftCard::MODULE_CODE, '30', false, 0, OrderPaymentPartState::SETTLED);
        $this->part($order, 'Cheque', '70', true, 1, OrderPaymentPartState::PENDING);

        // The listener alone does not deduct a second time what the part of the core accounts for.
        $event = $this->totalEvent($order);
        $event->setTotal(100.0);
        $this->listener()->deductWhatWasDebitedBeforePaymentParts($event);
        self::assertSame(100.0, $event->getTotal());

        // And the balance the core answers is the part of the provider.
        self::assertSame(70.0, $this->payTotal($order));
    }

    public function testTheListenerActsAfterTheCoreHasSetTheTotal(): void
    {
        $priorities = [];
        foreach ($this->dispatcher()->getListeners(TheliaEvents::ORDER_PAY_GET_TOTAL) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof PaymentListener) {
                $priorities[] = $this->dispatcher()->getListenerPriority(TheliaEvents::ORDER_PAY_GET_TOTAL, $listener);
            }
        }

        self::assertSame([PaymentListener::TOTAL_PRIORITY], $priorities);
        self::assertLessThan(128, PaymentListener::TOTAL_PRIORITY, 'The core sets the total at 128.');
    }

    public function testARowWithNoStatusIsDeductedLikeASpentOne(): void
    {
        $order = $this->orderOf(100.0);
        $this->debited($order, '30', null);
        $this->debited($order, '10');

        self::assertSame(60.0, $this->payTotal($order));
    }

    public function testARowWithNoStatusIsSpentForTheScopeTheLoopAndTheRelease(): void
    {
        $order = $this->orderOf(100.0);
        $giftCard = $this->giftCard(['amount' => '100', 'spendAmount' => '30']);
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount('30')->setInitialPostage('0')->setStatus(null)->save();
        $this->debited($order, '5', GiftCardPaymentService::STATUS_GIVEN_BACK);
        // Rows of ANOTHER order: the OR of the loop must not escape the filter on the order.
        $other = $this->orderOf(100.0);
        $this->debited($other, '7', null);
        $this->debited($other, '8', GiftCardPaymentService::STATUS_GIVEN_BACK);
        $this->debited($other, '9');

        self::assertSame(1, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->filterBySpent()->count());
        self::assertSame(2, GiftCardOrderQuery::create()->filterByOrderId($other->getId())->filterBySpent()->count());

        self::assertSame(1, $this->loopCriteria(['order_id' => $order->getId()])->count(), 'The loop shows it as spent by default.');
        self::assertSame(2, $this->loopCriteria(['order_id' => $order->getId(), 'status' => '0,1'])->count());
        self::assertSame(1, $this->loopCriteria(['order_id' => $order->getId(), 'status' => '1'])->count());
        self::assertSame(3, $this->loopCriteria(['order_id' => $other->getId(), 'status' => '0,1'])->count());

        $statuses = [];
        $loopResult = $this->loopResult(['order_id' => $order->getId()]);
        foreach ($loopResult as $row) {
            $statuses[] = $row->get('STATUS');
        }
        self::assertSame([GiftCardPaymentService::STATUS_SPENT], $statuses, 'STATUS of a row with no status is 0, as it is classed.');

        $service = new GiftCardPaymentService();
        $service->release((int) $order->getId());
        GiftCardTableMap::clearInstancePool();
        self::assertSame(0.0, (float) GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount());

        $service->release((int) $order->getId());
        GiftCardTableMap::clearInstancePool();
        self::assertSame(0.0, (float) GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount(), 'Given back once.');
        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->filterBySpent()->count());
    }

    public function testANegativeRowDoesNotMakeTheTotalFail(): void
    {
        $order = $this->orderOf(100.0);
        $this->debited($order, '-20');
        $this->debited($order, '10');

        self::assertSame(90.0, $this->payTotal($order));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function loopCriteria(array $arguments): ModelCriteria
    {
        $container = static::getContainer();
        $loop = new GiftCardOrderList();
        $loop->init(
            $container,
            $container->get('request_stack'),
            $this->dispatcher(),
            $container->get(SecurityContext::class),
            $container->get('thelia.translator'),
            [],
            'test',
        );
        $loop->initializeArgs($arguments + ['type' => 'gift.card.order.list', 'name' => 'test']);

        return $loop->buildModelCriteria();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function loopResult(array $arguments): LoopResult
    {
        $container = static::getContainer();
        $loop = new GiftCardOrderList();
        $loop->init(
            $container,
            $container->get('request_stack'),
            $this->dispatcher(),
            $container->get(SecurityContext::class),
            $container->get('thelia.translator'),
            [],
            'test',
        );
        $loop->initializeArgs($arguments + ['type' => 'gift.card.order.list', 'name' => 'test']);

        return $loop->parseResults(new LoopResult($loop->buildModelCriteria()->find()));
    }

    private function orderOf(float $total): Order
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order();
        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(['isDefault' => false]), $fixtures->currency(), ['basePrice' => $total]);
        $this->orderLine($order, $product, 1, $total);

        return $order;
    }

    private function debited(Order $order, string $amount, ?int $status = GiftCardPaymentService::STATUS_SPENT): void
    {
        $giftCard = $this->giftCard(['amount' => '100', 'spendAmount' => $amount]);
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount($amount)->setInitialPostage('0')->setStatus($status)->save();
    }

    private function part(Order $order, string $moduleCode, string $amount, bool $isPrimary, int $position, OrderPaymentPartState $state): void
    {
        $module = ModuleQuery::create()->findOneByCode($moduleCode);
        self::assertNotNull($module, \sprintf('The test database has no %s module.', $moduleCode));

        (new OrderPaymentPart())
            ->setOrderId($order->getId())
            ->setPaymentModuleId($module->getId())
            ->setAmount($amount)
            ->setState($state->value)
            ->setIsPrimary($isPrimary)
            ->setPosition($position)
            ->save();
    }

    private function totalEvent(Order $order): OrderPayTotalEvent
    {
        $event = new OrderPayTotalEvent($order);

        return $event->setTax(0)->setIncludePostage(true)->setIncludeDiscount(true);
    }

    private function payTotal(Order $order): float
    {
        $event = $this->totalEvent($order);
        $this->dispatcher()->dispatch($event, TheliaEvents::ORDER_PAY_GET_TOTAL);

        return (float) $event->getTotal();
    }

    private function listener(): PaymentListener
    {
        $listener = static::getContainer()->get(PaymentListener::class);
        self::assertInstanceOf(PaymentListener::class, $listener);

        return $listener;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
