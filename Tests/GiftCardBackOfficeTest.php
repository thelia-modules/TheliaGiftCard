<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\Connection\ConnectionWrapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Admin;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardListInfoProvider;

/**
 * The back-office screens of the module: rights, card list data, escaping.
 */
final class GiftCardBackOfficeTest extends GiftCardTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutes(): iterable
    {
        yield 'PDF download' => ['GET', '/admin/module/theliagiftcard/config/send/pdf?code=%s&l=fr_FR'];
        yield 'manual creation' => ['POST', '/admin/module/theliagiftcard/generate-gift-card'];
        yield 'activation' => ['POST', '/admin/module/theliagiftcard/activate?code=%s'];
        yield 'deactivation' => ['POST', '/admin/module/theliagiftcard/deactivate?code=%s'];
        yield 'edition' => ['POST', '/admin/module/theliagiftcard/edit-gift-card'];
        yield 'configuration' => ['POST', '/admin/module/theliagiftcard/config/save'];
        yield 'card detail' => ['GET', '/admin/module/theliagiftcard/show/%s'];
        yield 'card list' => ['GET', '/admin/module/theliagiftcard/show'];
    }

    #[DataProvider('protectedRoutes')]
    public function testAnAdministratorWithoutRightsOnTheModuleIsRefused(string $method, string $path): void
    {
        $giftCard = $this->giftCard(['status' => 0]);

        $response = $this->asAdmin($this->createFixtureFactory()->restrictedAdmin([]), $method, \sprintf($path, $giftCard->getCode()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
    }

    public function testTheCardListLoadsNamesAndReferencesWithAFixedNumberOfQueries(): void
    {
        $this->cardsWithCustomersAndOrders(2);
        $fewCards = $this->countQueries(fn () => (new GiftCardListInfoProvider())->build());

        $this->cardsWithCustomersAndOrders(6);
        $info = null;
        $manyCards = $this->countQueries(function () use (&$info): void {
            $info = (new GiftCardListInfoProvider())->build();
        });

        self::assertSame($fewCards, $manyCards);
        self::assertContains('Ada Sponsor', $info['sponsor_customers']);
        self::assertContains('Bea Beneficiary', $info['beneficiary_customers']);
    }

    public function testCustomerNamesReachTheCardListScriptEncoded(): void
    {
        $fixtures = $this->createFixtureFactory();
        $sponsor = $fixtures->customer($fixtures->customerTitle(), ['firstname' => '<img src=x onerror=alert(1)>', 'lastname' => 'Doe']);
        $this->giftCard()->setSponsorCustomerId($sponsor->getId())->save();

        $content = (string) $this->asAdmin($fixtures->admin(), 'GET', '/admin/module/TheliaGiftCard')->getContent();

        self::assertStringContainsString('var all_info', $content);
        self::assertStringNotContainsString('<img src=x', $content);
        self::assertStringContainsString(trim(json_encode('<img src=x onerror=alert(1)>', \JSON_HEX_TAG), '"'), $content);
    }

    private function cardsWithCustomersAndOrders(int $count): void
    {
        $fixtures = $this->createFixtureFactory();

        for ($i = 0; $i < $count; ++$i) {
            $sponsor = $fixtures->customer($fixtures->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Sponsor']);
            $beneficiary = $fixtures->customer($fixtures->customerTitle(), ['firstname' => 'Bea', 'lastname' => 'Beneficiary']);
            $this->giftCard(['orderId' => $fixtures->order($sponsor)->getId(), 'beneficiaryCustomerId' => $beneficiary->getId()])
                ->setSponsorCustomerId($sponsor->getId())
                ->save();
        }
    }

    private function countQueries(callable $callback): int
    {
        $connection = $this->getPropelConnection();
        self::assertInstanceOf(ConnectionWrapper::class, $connection);
        $connection->useDebug(true);
        $before = $connection->getQueryCount();

        try {
            $callback();

            return $connection->getQueryCount() - $before;
        } finally {
            $connection->useDebug(false);
        }
    }

    private function asAdmin(Admin $admin, string $method, string $uri): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($admin);
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));

        $request = Request::create($uri, $method);
        $request->setSession($session);

        return $this->handleAsMainRequest($request);
    }
}
