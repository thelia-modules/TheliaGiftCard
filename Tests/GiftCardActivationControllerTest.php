<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Customer;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardActivationLimiter;

/**
 * The "activate a gift card" field of the customer area, through the kernel.
 */
final class GiftCardActivationControllerTest extends GiftCardTestCase
{
    private string $clientIp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientIp = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    public function testTheCustomerTypingAValidCodeGetsTheCard(): void
    {
        $customer = $this->customer();
        $giftCard = $this->giftCard();

        $response = $this->postCode($customer, $giftCard->getCode());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($customer->getId(), GiftCardQuery::create()->findPk($giftCard->getId())?->getBeneficiaryCustomerId());
    }

    public function testACustomerWhoTriedTooManyCodesCannotAttachEvenAValidOne(): void
    {
        $customer = $this->customer();
        $giftCard = $this->giftCard();

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->postCode($customer, 'WRONG'.$attempt);
        }
        $this->postCode($customer, $giftCard->getCode());

        self::assertNull(GiftCardQuery::create()->findPk($giftCard->getId())?->getBeneficiaryCustomerId());
    }

    private function postCode(Customer $customer, string $code): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setCustomerUser($customer);

        $request = Request::create('/gift-card/activate-code', 'POST', server: ['REMOTE_ADDR' => $this->clientIp]);
        $request->setSession($session);
        $request->request->set('activate_gift_card_to_customer', [
            'code_gift_card' => $code,
            'success_url' => '/account',
            'error_url' => '/account',
            '_token' => $this->csrfToken($request, 'activate_gift_card_to_customer'),
        ]);

        return $this->handleAsMainRequest($request);
    }

    /**
     * Ids are not rolled back but can come back after the test database is prepared again, and
     * the limiter keeps its counters in the cache of the test environment: they start from zero.
     */
    private function customer(): Customer
    {
        $fixtures = $this->createFixtureFactory();
        $customer = $fixtures->customer($fixtures->customerTitle());

        $this->limiterFactory(GiftCardActivationLimiter::PER_CUSTOMER_LIMITER)->create('customer:'.$customer->getId())->reset();
        $this->limiterFactory(GiftCardActivationLimiter::PER_CLIENT_LIMITER)->create('client:'.$this->clientIp)->reset();

        return $customer;
    }

    private function limiterFactory(string $serviceId): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get($serviceId);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }
}
