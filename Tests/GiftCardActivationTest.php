<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Thelia\Model\Customer;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardActivationLimiter;
use TheliaGiftCard\Service\GiftCardActivationService;

final class GiftCardActivationTest extends GiftCardTestCase
{
    public function testAnEnabledUnexpiredFreeCardIsAttachedToTheCustomer(): void
    {
        $customer = $this->customer();
        $giftCard = $this->giftCard();

        self::assertTrue((new GiftCardActivationService())->attachToCustomer(' '.$giftCard->getCode().' ', $customer->getId()));
        self::assertSame($customer->getId(), $this->beneficiaryOf($giftCard->getId()));
    }

    public function testACardExpiringTodayCanStillBeAttached(): void
    {
        $giftCard = $this->giftCard(['expirationDate' => new \DateTime('today')]);

        self::assertTrue((new GiftCardActivationService())->attachToCustomer($giftCard->getCode(), $this->customer()->getId()));
    }

    public function testADisabledCardIsRefused(): void
    {
        $giftCard = $this->giftCard(['status' => 0]);

        self::assertFalse((new GiftCardActivationService())->attachToCustomer($giftCard->getCode(), $this->customer()->getId()));
        self::assertNull($this->beneficiaryOf($giftCard->getId()));
    }

    public function testAnExpiredCardIsRefused(): void
    {
        $giftCard = $this->giftCard(['expirationDate' => new \DateTime('yesterday')]);

        self::assertFalse((new GiftCardActivationService())->attachToCustomer($giftCard->getCode(), $this->customer()->getId()));
        self::assertNull($this->beneficiaryOf($giftCard->getId()));
    }

    public function testACardAlreadyAttachedStaysWithItsHolder(): void
    {
        $holder = $this->customer();
        $giftCard = $this->giftCard(['beneficiaryCustomerId' => $holder->getId()]);

        self::assertFalse((new GiftCardActivationService())->attachToCustomer($giftCard->getCode(), $this->customer()->getId()));
        self::assertSame($holder->getId(), $this->beneficiaryOf($giftCard->getId()));
    }

    public function testAnUnknownCodeIsRefused(): void
    {
        self::assertFalse((new GiftCardActivationService())->attachToCustomer('NOSUCHCD', $this->customer()->getId()));
    }

    public function testTheLimiterStopsACustomerTryingCodeAfterCode(): void
    {
        $limiter = new GiftCardActivationLimiter($this->limiterFactory(3), $this->limiterFactory(100));

        self::assertTrue($limiter->allows(42, '10.0.0.1'));
        self::assertTrue($limiter->allows(42, '10.0.0.2'));
        self::assertTrue($limiter->allows(42, '10.0.0.3'));
        self::assertFalse($limiter->allows(42, '10.0.0.4'), 'A new address does not give a customer new attempts.');
        self::assertTrue($limiter->allows(43, '10.0.0.4'), 'Another customer keeps its own attempts.');
    }

    public function testTheLimiterStopsAClientWalkingThroughAccounts(): void
    {
        $limiter = new GiftCardActivationLimiter($this->limiterFactory(100), $this->limiterFactory(2));

        self::assertTrue($limiter->allows(1, '10.0.0.9'));
        self::assertTrue($limiter->allows(2, '10.0.0.9'));
        self::assertFalse($limiter->allows(3, '10.0.0.9'));
    }

    public function testTheModuleWiresBothLimiters(): void
    {
        self::assertInstanceOf(GiftCardActivationLimiter::class, $this->getService(GiftCardActivationLimiter::class));
    }

    private function limiterFactory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function customer(): Customer
    {
        $fixtures = $this->createFixtureFactory();

        return $fixtures->customer($fixtures->customerTitle());
    }

    private function beneficiaryOf(int $giftCardId): ?int
    {
        return GiftCardQuery::create()->findPk($giftCardId)?->getBeneficiaryCustomerId();
    }
}
