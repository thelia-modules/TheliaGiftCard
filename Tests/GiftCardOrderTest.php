<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\FeatureAvI18nQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use TheliaGiftCard\EventListener\OrderAfterPayListener;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardOrderQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardCodeGenerator;
use TheliaGiftCard\Service\GiftCardGenerateService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Cards created by a paid order, and taken back when that order is cancelled.
 */
final class GiftCardOrderTest extends GiftCardTestCase
{
    public function testAPaidOrderCreatesOneInactiveCardPerUnitForOneYear(): void
    {
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, '0', false, true);
        $order = $this->createFixtureFactory()->order();
        $this->orderLine($order, $this->giftCardProduct(50), 2, 50.0);

        $this->generator()->generateGifcard($order);

        $giftCards = $this->cardsOf($order);
        self::assertCount(2, $giftCards);
        self::assertNotSame($giftCards[0]->getCode(), $giftCards[1]->getCode());
        foreach ($giftCards as $giftCard) {
            self::assertSame(50.0, (float) $giftCard->getAmount());
            self::assertSame(0.0, (float) $giftCard->getSpendAmount());
            self::assertSame(0, $giftCard->getStatus());
            self::assertSame((new \DateTime('+1 year'))->format('Y-m-d'), $giftCard->getExpirationDate()?->format('Y-m-d'));
            self::assertSame($order->getCustomerId(), $giftCard->getSponsorCustomerId());
        }
    }

    public function testTheAmountIsThePriceWithItsTax(): void
    {
        $order = $this->createFixtureFactory()->order();
        $this->orderLine($order, $this->giftCardProduct(40), 1, 40.0, 8.0);

        $this->generator()->generateGifcard($order);

        self::assertSame(48.0, (float) $this->cardsOf($order)[0]->getAmount());
    }

    public function testTheAutomaticModeCreatesActiveCards(): void
    {
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, '1', false, true);
        $order = $this->createFixtureFactory()->order();
        $this->orderLine($order, $this->giftCardProduct(30), 1, 30.0);

        $this->generator()->generateGifcard($order);

        self::assertSame(1, $this->cardsOf($order)[0]->getStatus());
    }

    public function testGeneratingTwiceDoesNotCreateMoreCardsThanUnitsBought(): void
    {
        $order = $this->createFixtureFactory()->order();
        $this->orderLine($order, $this->giftCardProduct(30), 1, 30.0);

        $this->generator()->generateGifcard($order);
        $this->generator()->generateGifcard($order);

        self::assertCount(1, $this->cardsOf($order));
    }

    public function testCancellingThePurchaseDisablesTheCardsNothingWasSpentFrom(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $untouched = $this->giftCard(['orderId' => $order->getId()]);
        $started = $this->giftCard(['orderId' => $order->getId(), 'spendAmount' => '12.5']);
        $otherOrderCard = $this->giftCard(['orderId' => $this->createFixtureFactory()->order()->getId()]);

        $this->cancel($order);

        self::assertSame(0, $this->statusOf($untouched), 'A card nothing was spent from is taken back.');
        self::assertSame(1, $this->statusOf($started), 'A card already used stays as it is.');
        self::assertSame(1, $this->statusOf($otherOrderCard));
    }

    public function testAnOrderThatIsNotCancelledLeavesItsCardsAlone(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_SENT]);
        $giftCard = $this->giftCard(['orderId' => $order->getId()]);

        (new OrderAfterPayListener(static::getContainer()->get('request_stack')))->onOrderCancelGiftCard(new OrderEvent($order));

        self::assertSame(1, $this->statusOf($giftCard));
    }

    public function testTheForcedAmountIsReadInTheLanguageOfTheOrderFirst(): void
    {
        $order = $this->createFixtureFactory()->order();
        $product = $this->giftCardProduct(40);
        $orderLocale = (string) $order->getLang()?->getLocale();
        $this->forceAmount($product, [$orderLocale => '75', 'xx_XX' => '99']);
        $this->orderLine($order, $product, 1, 40.0);

        $this->generator()->generateGifcard($order);

        self::assertSame(75.0, (float) $this->cardsOf($order)[0]->getAmount());
    }

    public function testAForcedAmountMissingInTheLanguageOfTheOrderIsReadInAnotherOne(): void
    {
        $order = $this->createFixtureFactory()->order();
        $product = $this->giftCardProduct(40);
        $this->forceAmount($product, ['xx_XX' => '60']);
        $this->orderLine($order, $product, 1, 40.0);

        $this->generator()->generateGifcard($order);

        self::assertSame(60.0, (float) $this->cardsOf($order)[0]->getAmount());
    }

    public function testAnEmptyForcedAmountKeepsThePrice(): void
    {
        $order = $this->createFixtureFactory()->order();
        $product = $this->giftCardProduct(40);
        $this->forceAmount($product, [(string) $order->getLang()?->getLocale() => '']);
        $this->orderLine($order, $product, 1, 40.0);

        $this->generator()->generateGifcard($order);

        self::assertSame(40.0, (float) $this->cardsOf($order)[0]->getAmount());
    }

    public function testARefundedPurchaseIsTakenBackLikeACancelledOne(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $untouched = $this->giftCard(['orderId' => $order->getId()]);
        $started = $this->giftCard(['orderId' => $order->getId(), 'spendAmount' => '5']);

        $this->moveTo($order, OrderStatus::CODE_REFUNDED);

        self::assertSame(0, $this->statusOf($untouched));
        self::assertSame(1, $this->statusOf($started));
    }

    public function testCancellingAnOrderPaidWithACardGivesTheSpentAmountBack(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '30.000001']);
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount('12.5')->setInitialPostage('0')->save();

        $this->cancel($order);

        self::assertSame('17.500001', GiftCardQuery::create()->findPk($giftCard->getId())?->getSpendAmount());
        self::assertSame(0, GiftCardOrderQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testTheCancellationGoesThroughTheOrderStatusEventOfTheCore(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $untouched = $this->giftCard(['orderId' => $order->getId()]);
        $canceled = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_CANCELED);
        self::assertNotNull($canceled);

        $event = new OrderEvent($order);
        $event->setStatus($canceled->getId());
        static::getContainer()->get('event_dispatcher')->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

        self::assertSame(0, $this->statusOf($untouched));
    }

    /**
     * @param array<string, string> $titles the value typed as the amount, by locale
     */
    private function forceAmount(Product $product, array $titles): void
    {
        $fixtures = $this->createFixtureFactory();
        $featureAv = $fixtures->featureAv($fixtures->feature());
        foreach ($titles as $locale => $title) {
            $featureAv->setLocale($locale)->setTitle($title)->save();
        }
        // The fixture names the value in en_US: that title must not answer for the others.
        if (!\array_key_exists('en_US', $titles)) {
            FeatureAvI18nQuery::create()->filterById($featureAv->getId())->filterByLocale('en_US')->delete();
        }
        $fixtures->featureProduct($product, $featureAv)->setFreeTextValue('1')->save();
    }

    private function moveTo(Order $order, string $statusCode): void
    {
        $status = OrderStatusQuery::create()->findOneByCode($statusCode);
        self::assertNotNull($status);

        $order->setStatusId($status->getId())->save();
        $order->setOrderStatus($status);

        (new OrderAfterPayListener(static::getContainer()->get('request_stack')))->onOrderCancelGiftCard(new OrderEvent($order));
    }

    private function cancel(Order $order): void
    {
        $this->moveTo($order, OrderStatus::CODE_CANCELED);
    }

    private function generator(): GiftCardGenerateService
    {
        return new GiftCardGenerateService(new GiftCardCodeGenerator());
    }

    /**
     * @return list<GiftCard>
     */
    private function cardsOf(Order $order): array
    {
        return array_values(iterator_to_array(GiftCardQuery::create()->filterByOrderId($order->getId())->orderById()->find()));
    }

    private function statusOf(GiftCard $giftCard): ?int
    {
        return GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus();
    }
}
