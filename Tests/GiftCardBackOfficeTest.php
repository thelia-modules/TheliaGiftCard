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
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Admin;
use TheliaGiftCard\Hook\HookConfigurationManager;
use TheliaGiftCard\Hook\HookManager;
use TheliaGiftCard\Model\GiftCardOrder;
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

    public function testTheCardListScriptOnlyAddressesTheColumnsOfTheTable(): void
    {
        $this->giftCard();
        [$page, $script] = $this->renderConfigurationScreen('fr_FR');

        $headerCells = preg_match_all('/<th\b/', (string) strstr((string) strstr($page, '<thead>'), '</thead>', true));
        preg_match_all('/targets: \[(\d+)\]/', $script, $targets);

        self::assertGreaterThan(0, $headerCells);
        self::assertNotEmpty($targets[1]);
        self::assertLessThan($headerCells, max(array_map('intval', $targets[1])), 'The script renders a column the table has no header for.');
        foreach (['pdf', 'action', 'edit'] as $unsortable) {
            self::assertMatchesRegularExpression('/\{"name":"'.$unsortable.'"[^}]*"orderable":false/', $script, $unsortable.' has no ORM column to sort on.');
        }

        $response = $this->asAdmin($this->createFixtureFactory()->admin(), 'GET', '/admin/module/theliagiftcard/show');
        $rows = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['data'];

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertCount($headerCells, $row);
        }
    }

    public function testTheBackOfficeScreenIsTranslated(): void
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order($fixtures->customer($fixtures->customerTitle()));
        $giftCard = $this->giftCard();
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount('12.5')->setInitialPostage('0')->save();

        [$page, $script, $orderBlock] = $this->renderConfigurationScreen('fr_FR', $order->getId());

        self::assertStringContainsString('Gestion des cartes cadeaux', $page);
        self::assertStringContainsString('Créer une carte cadeau', $page);
        self::assertStringContainsString('Achetées / Générées', $page);
        self::assertStringContainsString('<th>Modifier</th>', $page);
        self::assertStringContainsString('"sSearch": "Rechercher ', $script);
        self::assertStringContainsString('"sInfo": "Affichage des éléments _START_ à _END_ sur _TOTAL_"', $script);
        self::assertStringContainsString('Montant de carte cadeau utilisé pour cette commande', $orderBlock);
        self::assertStringContainsString('Montant carte cadeau '.$giftCard->getCode().' : 12.50', $orderBlock);
        self::assertStringContainsString('Total payé en carte cadeau', $orderBlock);

        [$page, $script, $orderBlock] = $this->renderConfigurationScreen('de_DE', $order->getId());

        self::assertStringContainsString('Neue Geschenkkarte erstellen', $page);
        self::assertStringContainsString('<th>Erstellungsdatum</th>', $page);
        self::assertStringContainsString('<th>Ausgegebener Betrag</th>', $page);
        self::assertStringNotContainsString('<th>spend_amount</th>', $page);
        self::assertStringContainsString('"sInfo": "Elemente _START_ bis _END_ von insgesamt _TOTAL_"', $script);
        self::assertStringContainsString('Betrag der Geschenkkarte '.$giftCard->getCode().': 12.50', $orderBlock);
        self::assertStringContainsString('Mit Geschenkkarten bezahlt insgesamt', $orderBlock);
    }

    /**
     * The fragments the module adds to its configuration page (the table and the script that fills it),
     * and to the order page when an order id is given.
     *
     * @return array{string, string, string}
     */
    private function renderConfigurationScreen(string $locale, ?int $orderId = null): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang($locale));

        $request = Request::create('/admin/module/TheliaGiftCard');
        $request->setSession($session);
        $request->setLocale($locale);

        $requestStack = $this->requestStack();
        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }
        $requestStack->push($request);

        // The locale the admin language listener gives both translators on a real request.
        $translator = Translator::getInstance();
        $previousLocale = $translator->getLocale();
        $translator->setLocale($locale);
        $twigTranslator = static::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $twigTranslator);
        $previousTwigLocale = $twigTranslator->getLocale();
        $twigTranslator->setLocale($locale);

        try {
            // As the module page does: the Twig parser renders with the back-office template.
            $parserResolver = static::getContainer()->get('thelia.parser.resolver');
            self::assertInstanceOf(ParserResolver::class, $parserResolver);
            $backOffice = $parserResolver->getCurrentTemplateDefinition();
            self::assertInstanceOf(TemplateDefinition::class, $backOffice);
            foreach ($parserResolver->getParsers() as $parser) {
                if ('html.twig' === $parser->getFileExtension()) {
                    $parser->setTemplateDefinition($backOffice, true);
                }
            }

            $hook = static::getContainer()->get(HookConfigurationManager::class);
            self::assertInstanceOf(HookConfigurationManager::class, $hook);

            $configuration = new HookRenderEvent('module.configuration', ['modulecode' => 'TheliaGiftCard']);
            $hook->onConfiguration($configuration);
            $script = new HookRenderEvent('module.config-js', ['modulecode' => 'TheliaGiftCard']);
            $hook->onProductEditJs($script);

            $orderBlock = new HookRenderEvent('order-edit.after-order-product-list', ['order_id' => (string) $orderId]);
            if (null !== $orderId) {
                $orderHook = static::getContainer()->get(HookManager::class);
                self::assertInstanceOf(HookManager::class, $orderHook);
                $orderHook->cardGiftAccountUsageInOrder($orderBlock);
            }

            return [implode('', $configuration->get()), implode('', $script->get()), implode('', $orderBlock->get())];
        } finally {
            $translator->setLocale($previousLocale);
            $twigTranslator->setLocale($previousTwigLocale);
            while (null !== $requestStack->pop()) {
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
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
