<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionWrapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Admin;
use TheliaGiftCard\Hook\HookConfigurationManager;
use TheliaGiftCard\Hook\HookManager;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardListInfoProvider;
use TheliaGiftCard\Service\GiftCardPaymentService;

/**
 * The back-office screens of the module: rights, card list data, escaping.
 */
final class GiftCardBackOfficeTest extends GiftCardTestCase
{
    private const TOKEN = 'gift-card-back-office-token';

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
    }

    #[DataProvider('protectedRoutes')]
    public function testAnAdministratorWithoutRightsOnTheModuleIsRefused(string $method, string $path): void
    {
        $giftCard = $this->giftCard(['status' => 0]);

        $response = $this->asAdmin($this->createFixtureFactory()->restrictedAdmin([]), $method, \sprintf($path, $giftCard->getCode()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
    }

    public function testTheJsonRouteOfTheOldDataTableIsGone(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlMatcherInterface::class, $router);

        $this->expectException(ResourceNotFoundException::class);
        $router->match('/admin/module/theliagiftcard/show');
    }

    public function testTheCardListLoadsNamesAndReferencesWithAFixedNumberOfQueries(): void
    {
        $this->cardsWithCustomersAndOrders(2);
        $fewCards = $this->countQueries(fn () => (new GiftCardListInfoProvider())->build($this->allCards()));

        $this->cardsWithCustomersAndOrders(6);
        $info = null;
        $manyCards = $this->countQueries(function () use (&$info): void {
            $info = (new GiftCardListInfoProvider())->build($this->allCards());
        });

        self::assertSame($fewCards, $manyCards);
        self::assertContains('Ada Sponsor', array_column($info['sponsor_customers'], 'name'));
        self::assertContains('Bea Beneficiary', array_column($info['beneficiary_customers'], 'name'));
    }

    public function testTheRightsOfARestrictedAdministratorAreReadOncePerPageNotOncePerButton(): void
    {
        $admin = $this->createFixtureFactory()->restrictedAdmin([]);
        $this->giftCard(['code' => 'RGT001']);
        $this->giftCard(['code' => 'RGT002']);
        // A first page fills the caches of the request (languages, configuration): it is not measured.
        $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=RGT', $admin);
        $twoCards = $this->countQueries(fn () => $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=RGT', $admin));

        for ($i = 3; $i <= 25; ++$i) {
            $this->giftCard(['code' => \sprintf('RGT%03d', $i)]);
        }
        $page = null;
        $aPageOfCards = $this->countQueries(function () use (&$page, $admin): void {
            [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=RGT', $admin);
        });

        self::assertSame($twoCards, $aPageOfCards);
        self::assertSame(25, $this->xpath((string) $page)->query('//tr[@data-testid="datatable-gift-cards-row"]')->length);
        self::assertStringNotContainsString('bo-action--', (string) $page, 'an administrator without the right to change the module has no action');
    }

    public function testTheInformationOfThePageOnlyReadsTheCardsOfThePage(): void
    {
        $this->cardsWithCustomersAndOrders(3);
        $cards = $this->allCards();
        $info = (new GiftCardListInfoProvider())->build([$cards[0]]);

        self::assertCount(1, $info['orders']);
        self::assertSame($cards[0]->getOrderId(), array_key_first($info['orders']));
        self::assertCount(1, $info['sponsor_customers']);
        self::assertCount(1, $info['beneficiary_customers']);
    }

    public function testCustomerNamesAreEscapedInTheCardList(): void
    {
        $fixtures = $this->createFixtureFactory();
        $sponsor = $fixtures->customer($fixtures->customerTitle(), ['firstname' => '<img src=x onerror=alert(1)>', 'lastname' => 'Doe']);
        $beneficiary = $fixtures->customer($fixtures->customerTitle(), ['firstname' => '"><script>alert(2)</script>', 'lastname' => 'Roe']);
        $order = $fixtures->order($sponsor);
        $order->setRef('<b>REF</b>')->save();
        $this->giftCard(['orderId' => $order->getId(), 'code' => 'ESCAPE1'])->setSponsorCustomerId($sponsor->getId())->setBeneficiaryCustomerId($beneficiary->getId())->save();

        [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=ESCAPE1');

        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringNotContainsString('<script>alert(2)', $page);
        self::assertStringNotContainsString('<b>REF</b>', $page);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $page);
        self::assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $page);
        self::assertStringContainsString('&lt;b&gt;REF&lt;/b&gt;', $page);
    }

    public function testEveryRowOfTheCardListHasACellForEachColumnAndTheColumnsCanBeSorted(): void
    {
        $this->giftCard(['code' => 'CELLS1']);
        [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=CELLS1&type=generated');
        $xpath = $this->xpath($page);

        $headerCells = $xpath->query('//table[@data-testid="datatable-gift-cards-table"]//thead//th')->length;
        $rows = $xpath->query('//tr[@data-testid="datatable-gift-cards-row"]');

        self::assertGreaterThan(5, $headerCells);
        self::assertSame(1, $rows->length);
        self::assertSame($headerCells, $xpath->query('./td', $rows->item(0))->length);

        foreach (['code', 'amount', 'expiration_date', 'status'] as $sort) {
            $link = $xpath->query('//a[@data-testid="datatable-gift-cards-sort-'.$sort.'"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $link, 'The column '.$sort.' has no sort link.');
            self::assertStringContainsString('/admin/module/TheliaGiftCard?', $link->getAttribute('href'));
            self::assertStringContainsString('q=CELLS1', $link->getAttribute('href'), 'A sort keeps the search.');
            self::assertStringContainsString('type=generated', $link->getAttribute('href'), 'A sort keeps the filters.');
            self::assertStringContainsString('order='.$sort, $link->getAttribute('href'));
        }
    }

    public function testARowShowsItsOrderItsPeopleItsAmountsAndItsActions(): void
    {
        $fixtures = $this->createFixtureFactory();
        $buyer = $fixtures->customer($fixtures->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Sponsor']);
        $address = $fixtures->address($buyer);
        $address->setIsDefault(1)->save();
        $country = (string) $address->getCountry()->getIsoalpha2();
        $order = $fixtures->order($buyer);
        $order->setRef('ORD-ROW-1')->save();
        $this->giftCard(['code' => 'ROWBOUGHT', 'orderId' => $order->getId(), 'amount' => '50', 'spendAmount' => '12.5', 'expirationDate' => new \DateTime('2031-03-04')])
            ->setSponsorCustomerId($buyer->getId())
            ->save();
        $this->giftCard(['code' => 'ROWOFF', 'status' => 0]);

        [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=ROW');
        $xpath = $this->xpath($page);

        $bought = $this->row($xpath, 'ROWBOUGHT');
        $orderLink = $xpath->query('.//a[@href="/admin/order/update/'.$order->getId().'"]', $bought)->item(0);
        self::assertSame('ORD-ROW-1', $orderLink?->textContent);
        self::assertStringContainsString('Ada Sponsor', $bought->textContent);
        self::assertStringContainsString((string) $buyer->getEmail(), $bought->textContent, 'the buyer is shown with their email, as on the order list');
        self::assertStringContainsString(CountryFlag::of($country).' Ada Sponsor', $bought->textContent, 'and with the flag of their country before their name');
        $buyerLink = $xpath->query('.//a[@href="/admin/customer/update?customer_id='.$buyer->getId().'"]', $bought)->item(0);
        self::assertSame('Ada Sponsor', $buyerLink?->textContent, 'and their name opens their customer account');
        self::assertStringContainsString('Non utilisée', $bought->textContent, 'a card nobody used has no beneficiary yet');
        self::assertMatchesRegularExpression('/50,00\s*€/u', $xpath->query('.//span[@dir="ltr"]', $bought)->item(0)?->textContent ?? '');
        self::assertMatchesRegularExpression('/12,50\s*€/u', $xpath->query('.//span[@dir="ltr"]', $bought)->item(1)?->textContent ?? '');
        self::assertStringContainsString('04/03/2031', $bought->textContent);
        self::assertSame('Activée', trim($xpath->query('.//span[contains(@class, "badge")]', $bought)->item(0)?->textContent ?? ''));

        // Every action is a button of the row, none is hidden in a menu.
        self::assertSame(0, $xpath->query('.//*[@data-testid="datatable-action-overflow"]', $bought)->length);
        self::assertSame(1, $xpath->query('.//*[contains(@class, "btn") and contains(@class, "bo-action--send")]', $bought)->length);
        self::assertSame(1, $xpath->query('.//*[contains(@class, "btn") and contains(@class, "bo-action--deactivate")]', $bought)->length);

        // Edit opens the window of the module on the code of the card.
        $edit = $xpath->query('.//*[@data-testid="datatable-action-edit"]', $bought)->item(0);
        self::assertSame('#manualy_edit_card_gift_dialog', $edit?->getAttribute('data-bs-target'));
        self::assertSame('ROWBOUGHT', $edit?->getAttribute('data-code'));
        $send = $xpath->query('.//*[@data-bs-target="#send_gift_card_email"]', $bought)->item(0);
        self::assertSame('ROWBOUGHT', $send?->getAttribute('data-code'));
        $deactivate = $xpath->query('.//*[@data-gift-card-status-url]', $bought)->item(0);
        self::assertSame('/admin/module/theliagiftcard/deactivate', $deactivate?->getAttribute('data-gift-card-status-url'), 'the code is posted, never put in the address');
        self::assertSame('ROWBOUGHT', $deactivate?->getAttribute('data-code'));
        $pdfs = $xpath->query('.//a[contains(@href, "/config/send/pdf?")]', $bought);
        self::assertGreaterThanOrEqual(1, $pdfs->length);
        self::assertStringContainsString('code=ROWBOUGHT', $pdfs->item(0)?->getAttribute('href') ?? '');
        self::assertStringContainsString('l=', $pdfs->item(0)?->getAttribute('href') ?? '');

        // A card that is not active can be edited and activated, and neither sent nor printed.
        $off = $this->row($xpath, 'ROWOFF');
        self::assertSame('Non activée', trim($xpath->query('.//span[contains(@class, "badge")]', $off)->item(0)?->textContent ?? ''));
        self::assertStringContainsString('Carte manuelle', $xpath->query('./td[@data-testid="datatable-gift-cards-cell-code_html"]', $off)->item(0)?->textContent ?? '');
        self::assertSame(0, $xpath->query('.//*[@data-testid="datatable-action-overflow"]', $off)->length);
        self::assertSame(1, $xpath->query('.//*[contains(@class, "btn") and contains(@class, "bo-action--activate")]', $off)->length);
        self::assertSame('/admin/module/theliagiftcard/activate', $xpath->query('.//*[@data-gift-card-status-url]', $off)->item(0)?->getAttribute('data-gift-card-status-url'));
        self::assertSame(0, $xpath->query('.//*[@data-bs-target="#send_gift_card_email"]', $off)->length);
        self::assertSame(0, $xpath->query('.//a[contains(@href, "/config/send/pdf?")]', $off)->length);
    }

    public function testTheStatusFormCarriesTheTokenAndTheListItReturnsTo(): void
    {
        $this->giftCard(['code' => 'FORM1', 'status' => 0]);

        [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=FORM&status=inactive&order=amount&direction=asc&page=1&stray=1');
        $xpath = $this->xpath($page);
        $form = $xpath->query('//form[@id="gift-card-status-form"]')->item(0);

        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame('post', strtolower($form->getAttribute('method')));
        $token = $xpath->query('.//input[@name="_token"]', $form)->item(0);
        self::assertNotSame('', $token?->getAttribute('value'));
        self::assertSame('q=FORM&status=inactive&order=amount&direction=asc', $xpath->query('.//input[@name="list"]', $form)->item(0)?->getAttribute('value'), 'only what the list knows is carried');
    }

    public function testTheTypeAndStatusChipsKeepTheSearchAndTheSortAndDropThePage(): void
    {
        [$page] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=chip&type=purchased&order=code&direction=asc&page=3');
        $xpath = $this->xpath($page);

        $generated = $xpath->query('//a[@data-testid="gift-card-type-generated"]')->item(0);
        self::assertSame('/admin/module/TheliaGiftCard?q=chip&type=generated&order=code&direction=asc', $generated?->getAttribute('href'));
        self::assertSame('btn btn-sm btn-primary', $xpath->query('//a[@data-testid="gift-card-type-purchased"]')->item(0)?->getAttribute('class'), 'the chip of the current type is the active one');
        $inactive = $xpath->query('//a[@data-testid="gift-card-status-inactive"]')->item(0);
        self::assertSame('/admin/module/TheliaGiftCard?q=chip&type=purchased&status=inactive&order=code&direction=asc', $inactive?->getAttribute('href'));
        self::assertSame('/admin/module/TheliaGiftCard', $xpath->query('//a[@data-testid="gift-card-reset"]')->item(0)?->getAttribute('href'));
    }

    public function testTheListIsCutIntoPagesThatKeepTheSearch(): void
    {
        for ($i = 1; $i <= 26; ++$i) {
            $this->giftCard(['code' => \sprintf('PGE%03d', $i)]);
        }

        [$firstPage] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=PGE&order=code&direction=asc');
        $first = $this->xpath($firstPage);
        self::assertSame(25, $first->query('//tr[@data-testid="datatable-gift-cards-row"]')->length, 'the first page is bounded');
        self::assertSame('/admin/module/TheliaGiftCard?q=PGE&order=code&direction=asc&page=2', $first->query('//nav[@data-testid="gift-card-pagination"]//a[normalize-space()="2"]')->item(0)?->getAttribute('href'));

        [$secondPage] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=PGE&order=code&direction=asc&page=2');
        $second = $this->xpath($secondPage);
        self::assertSame(1, $second->query('//tr[@data-testid="datatable-gift-cards-row"]')->length);
        self::assertStringContainsString('PGE026', $secondPage);
    }

    public function testTheCardListHasNoThirdPartyScriptNorJquery(): void
    {
        $this->giftCard();
        [$page, $script] = $this->renderConfigurationScreen('fr_FR');

        foreach ([$page, $script] as $fragment) {
            self::assertStringNotContainsString('https://', $fragment);
            self::assertStringNotContainsString('<script src', $fragment);
            self::assertStringNotContainsString('<link rel="stylesheet"', $fragment);
        }
        foreach (['jQuery', '$(', '$.', 'DataTable', 'select2', 'flatpickr', 'lodash'] as $library) {
            self::assertStringNotContainsString($library, $script);
        }
        self::assertStringContainsString("addEventListener('show.bs.modal'", $script);
    }

    public function testTheBackOfficeScreenIsTranslated(): void
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order($fixtures->customer($fixtures->customerTitle()));
        $giftCard = $this->giftCard();
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount('12.5')->setInitialPostage('0')->save();

        [$page, , $orderBlock] = $this->renderConfigurationScreen('fr_FR', $order->getId());

        self::assertStringContainsString('Gestion des cartes cadeaux', $page);
        self::assertStringContainsString('Créer une carte cadeau', $page);
        self::assertStringContainsString('Achetées / Générées', $page);
        self::assertStringContainsString('Tous les statuts', $page);
        self::assertStringContainsString('Appliquer', $page);
        self::assertStringContainsString('Montant utilisé', $page);
        self::assertStringContainsString('Statut', $page);
        self::assertStringContainsString('Code / commande', $page);
        self::assertStringContainsString('Acheteur', $page);
        self::assertStringContainsString('Montant de carte cadeau utilisé pour cette commande', $orderBlock);
        self::assertStringContainsString('Montant carte cadeau '.$giftCard->getCode().' : 12.50', $orderBlock);
        self::assertStringContainsString('Total payé en carte cadeau', $orderBlock);
        self::assertStringContainsString('E-mail du bénéficiaire', $page);
        self::assertStringNotContainsString('FORM_ADD_', $page, 'every label of the creation and send windows is translated');
        self::assertStringNotContainsString('manualy_create_gift_card[beneficiary_email]', $page, 'the creation window asks for the recipient e-mail once');
        self::assertStringContainsString('<th>Statut de la commande</th>', $page);

        [$page, , $orderBlock] = $this->renderConfigurationScreen('de_DE', $order->getId());

        self::assertStringContainsString('Neue Geschenkkarte erstellen', $page);
        self::assertStringContainsString('Verwendeter Betrag', $page);
        self::assertStringContainsString('Alle Status', $page);
        self::assertStringContainsString('Code / Bestellung', $page);
        self::assertStringContainsString('Käufer', $page);
        self::assertStringNotContainsString('Amount spent', $page);
        self::assertStringNotContainsString('All statuses', $page);
        self::assertStringContainsString('Betrag der Geschenkkarte '.$giftCard->getCode().': 12.50', $orderBlock);
        self::assertStringContainsString('Mit Geschenkkarten bezahlt insgesamt', $orderBlock);
        self::assertStringContainsString('E-Mail des Empfängers', $page);
        self::assertStringNotContainsString('FORM_ADD_', $page);
        self::assertStringContainsString('<th>Bestellstatus</th>', $page);
    }

    public function testARowGivenBackIsShownAsSpentNeitherOnTheOrderNorInTheHistoryOfTheCard(): void
    {
        $fixtures = $this->createFixtureFactory();
        $order = $fixtures->order($fixtures->customer($fixtures->customerTitle()));
        $giftCard = $this->giftCard();
        (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount('12.5')->setInitialPostage('0')->setStatus(GiftCardPaymentService::STATUS_GIVEN_BACK)->save();

        [, , $orderBlock] = $this->renderConfigurationScreen('fr_FR', $order->getId());
        $response = $this->asAdmin($fixtures->admin(), 'POST', '/admin/module/theliagiftcard/show/'.$giftCard->getCode());
        $card = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($giftCard->getCode(), $orderBlock);
        self::assertSame([], $card['HISTORY']);
    }

    public function testTheEditWindowShowsTheMessageAndWhatIsLeftOfTheCard(): void
    {
        $giftCard = $this->giftCard(['amount' => '50', 'spendAmount' => '12.5']);
        (new GiftCardInfoCart())->setGiftCardId($giftCard->getId())->setBeneficiaryMessage('<b>Joyeux</b> anniversaire')->save();
        [$page, $script] = $this->renderConfigurationScreen('fr_FR');

        $response = $this->asAdmin($this->createFixtureFactory()->admin(), 'GET', '/admin/module/theliagiftcard/show/'.$giftCard->getCode());
        $card = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('<b>Joyeux</b> anniversaire', $card['beneficiary_message']);
        self::assertMatchesRegularExpression('/^12,50\s*€$/u', $card['spent']);
        self::assertMatchesRegularExpression('/^37,50\s*€$/u', $card['remaining']);
        self::assertStringContainsString('id="giftCardSpent"', $page);
        self::assertStringContainsString('Déjà dépensé : %spent%, reste %remaining%', $page);
        self::assertStringContainsString('id="giftCardMessage"', $page);
        // The message is written as text, never as markup.
        self::assertStringContainsString('message.textContent', $script);
        self::assertStringNotContainsString('message.innerHTML', $script);
    }

    public function testTheExpirationDateIsWrittenAsTheLanguageOfTheInterfaceWritesIt(): void
    {
        $this->giftCard(['code' => 'DATEDE', 'expirationDate' => new \DateTime('2031-03-04')]);

        [$french] = $this->renderConfigurationScreen('fr_FR', null, '/admin/module/TheliaGiftCard?q=DATEDE');
        [$german] = $this->renderConfigurationScreen('de_DE', null, '/admin/module/TheliaGiftCard?q=DATEDE');

        // The format each language of the shop is set to (Languages in the configuration).
        $date = new \DateTime('2031-03-04');
        $frenchFormat = (string) $this->lang('fr_FR')->getDateFormat();
        $germanFormat = (string) $this->lang('de_DE')->getDateFormat();
        self::assertNotSame($frenchFormat, $germanFormat, 'the two languages of the test shop write dates differently');
        self::assertStringContainsString($date->format($frenchFormat), $french);
        self::assertStringContainsString($date->format($germanFormat), $german);
    }

    public function testTheEditWindowSaysWhenTheCardCannotBeRead(): void
    {
        [$page, $script] = $this->renderConfigurationScreen('fr_FR');

        self::assertStringContainsString('id="giftCardReadError"', $page);
        self::assertStringContainsString("La carte cadeau n'a pas pu être lue", html_entity_decode($page, \ENT_QUOTES));
        self::assertStringContainsString('showReadError(true)', $script);
        self::assertStringContainsString('save.disabled = failed', $script);
    }

    public function testTheEditWindowHasTheFieldsTheScriptFillsWithTheCard(): void
    {
        $giftCard = $this->giftCard();
        [$page, $script] = $this->renderConfigurationScreen('fr_FR');

        $response = $this->asAdmin($this->createFixtureFactory()->admin(), 'GET', '/admin/module/theliagiftcard/show/'.$giftCard->getCode());
        $card = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        preg_match_all("/setField\\('(\\w+)', card\\.(\\w+)\\)/", $script, $filled, \PREG_SET_ORDER);
        $fieldOf = [];
        foreach ($filled as [, $elementId, $key]) {
            $fieldOf[$elementId] = $key;
        }
        self::assertSame(
            ['amount' => 'amount', 'expirationDate' => 'expiration_date', 'beneficiaryAddress' => 'beneficiary_address', 'giftCardId' => 'card_id'],
            $fieldOf,
        );

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$page);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//*[@id="manualy_edit_card_gift_dialog"]//form')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);

        $expectedNames = ['amount' => 'amount', 'expirationDate' => 'expiration_date', 'beneficiaryAddress' => 'beneficiary_address', 'giftCardId' => 'gift_card_id'];
        foreach ($expectedNames as $elementId => $field) {
            $fields = $xpath->query('.//*[@id="'.$elementId.'"]', $form);
            self::assertSame(1, $fields->length, 'The script fills #'.$elementId.', which the edit window does not have.');
            $element = $fields->item(0);
            self::assertInstanceOf(\DOMElement::class, $element);
            self::assertSame('edit_gift_card['.$field.']', $element->getAttribute('name'));
            self::assertArrayHasKey($fieldOf[$elementId], $card, 'The card detail route does not give '.$fieldOf[$elementId].'.');
        }

        self::assertSame((float) $giftCard->getAmount(), (float) $card['amount']);
        self::assertSame($giftCard->getId(), $card['card_id']);
        self::assertSame('hidden', $xpath->query('.//*[@id="giftCardId"]', $form)->item(0)?->getAttribute('type'));
        self::assertFalse($xpath->query('.//*[@id="beneficiaryAddress"]', $form)->item(0)?->hasAttribute('required'), 'A card without a recipient e-mail could not be saved.');
        self::assertSame(1, $xpath->query('.//*[@id="logs-body"]', $form)->length, 'The script fills the usage history of the card into #logs-body.');
    }

    public function testTheListScriptIsToldTheStatusChangedWithoutReloadingThePage(): void
    {
        $giftCard = $this->giftCard(['status' => 0]);
        $uri = '/admin/module/theliagiftcard/%s?code='.$giftCard->getCode();

        $response = $this->postAsAdmin(\sprintf($uri, 'activate'), ['_token' => self::TOKEN], true);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame(1, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());

        $response = $this->postAsAdmin(\sprintf($uri, 'deactivate'), ['_token' => self::TOKEN], true);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
    }

    public function testAStatusFormSentAsAPageGoesBackToTheListWithItsSearchFiltersSortAndPage(): void
    {
        $giftCard = $this->giftCard(['status' => 0]);
        $uri = '/admin/module/theliagiftcard/activate?code='.$giftCard->getCode();

        $response = $this->postAsAdmin($uri, ['_token' => self::TOKEN, 'list' => 'q=abc&type=generated&status=inactive&order=amount&direction=asc&page=2'], false);

        self::assertTrue($response->isRedirect());
        self::assertSame(1, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
        $location = (string) $response->headers->get('Location');
        self::assertStringEndsWith('/admin/module/TheliaGiftCard?q=abc&type=generated&status=inactive&order=amount&direction=asc&page=2', $location);

        // The list posts the code in the body of the form.
        $response = $this->postAsAdmin('/admin/module/theliagiftcard/deactivate', ['_token' => self::TOKEN, 'code' => $giftCard->getCode()], false);
        self::assertTrue($response->isRedirect());
        self::assertStringEndsWith('/admin/module/TheliaGiftCard', (string) $response->headers->get('Location'), 'a form with no list goes back to the first page');
        self::assertSame(0, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
    }

    public function testAStatusFormCannotSendTheAdministratorSomewhereElse(): void
    {
        $giftCard = $this->giftCard(['status' => 0]);

        $response = $this->postAsAdmin('/admin/module/theliagiftcard/activate?code='.$giftCard->getCode(), ['_token' => self::TOKEN, 'list' => 'q=x&url=https://evil.example/&success_url=https://evil.example/&page=nope'], false);

        self::assertTrue($response->isRedirect());
        $location = (string) $response->headers->get('Location');
        self::assertStringNotContainsString('evil.example', $location);
        self::assertStringEndsWith('/admin/module/TheliaGiftCard?q=x', $location);
    }

    public function testAStatusFormWithoutItsTokenChangesNothing(): void
    {
        $giftCard = $this->giftCard(['status' => 0]);

        $response = $this->postAsAdmin('/admin/module/theliagiftcard/activate', ['_token' => 'forged', 'code' => $giftCard->getCode()], false);

        self::assertSame(403, $response->getStatusCode(), 'a forged token is refused as forbidden, not as a server error');
        self::assertSame(0, GiftCardQuery::create()->findPk($giftCard->getId())?->getStatus());
    }

    public function testTheEditFormRefusesAMissingCardWithoutAnError(): void
    {
        foreach (['', '999999999'] as $giftCardId) {
            $response = $this->postEditForm(['gift_card_id' => $giftCardId, 'amount' => '10', 'expiration_date' => '2030-01-01']);

            self::assertTrue($response->isRedirect(), 'gift_card_id "'.$giftCardId.'" answered '.$response->getStatusCode().'.');
        }
    }

    public function testACardCreatedByAnAdministratorKeepsTheRecipientEmail(): void
    {
        $before = GiftCardQuery::create()->count();

        $response = $this->postCreateForm([
            'sponsor_name' => 'Shop',
            'beneficiary_name' => 'Bob',
            'beneficiary_address' => 'bob@example.com',
            'beneficiary_message' => 'Happy birthday',
            'amount' => '25',
            'expiration_date' => '2031-01-01',
        ]);

        self::assertTrue($response->isRedirect());
        self::assertSame($before + 1, GiftCardQuery::create()->count());
        $created = GiftCardQuery::create()->orderById(Criteria::DESC)->findOne();
        $info = GiftCardInfoCartQuery::create()->findOneByGiftCardId($created?->getId());
        self::assertSame('bob@example.com', $info?->getBeneficiaryAddress());
        self::assertSame('Happy birthday', $info?->getBeneficiaryMessage());
    }

    public function testAnUnknownCardIsNotFound(): void
    {
        $response = $this->asAdmin($this->createFixtureFactory()->admin(), 'GET', '/admin/module/theliagiftcard/show/NOSUCHCARD');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testARecipientEmailThatIsNotAnEmailIsRefusedAndTheWindowComesBack(): void
    {
        $before = GiftCardQuery::create()->count();

        $response = $this->postCreateForm(['beneficiary_address' => 'not an e-mail', 'amount' => '25', 'expiration_date' => '2031-01-01']);

        self::assertTrue($response->isRedirect(), 'a refused field goes back to the window, not to an error page');
        self::assertSame($before, GiftCardQuery::create()->count());

        $giftCard = $this->giftCard();
        $this->postEditForm(['gift_card_id' => (string) $giftCard->getId(), 'amount' => '70', 'expiration_date' => '2030-01-01', 'beneficiary_address' => 'not an e-mail']);
        self::assertNull(GiftCardInfoCartQuery::create()->findOneByGiftCardId($giftCard->getId())?->getBeneficiaryAddress());
    }

    public function testTheRecipientEmailOfACardCanBeChangedAndRemoved(): void
    {
        $giftCard = $this->giftCard();
        $edit = ['gift_card_id' => (string) $giftCard->getId(), 'amount' => '70', 'expiration_date' => '2030-01-01'];
        $recipient = static fn (): ?string => GiftCardInfoCartQuery::create()->findOneByGiftCardId($giftCard->getId())?->getBeneficiaryAddress();

        $this->postEditForm($edit + ['beneficiary_address' => 'recipient@example.com']);
        self::assertSame('recipient@example.com', $recipient());
        self::assertSame(70.0, (float) GiftCardQuery::create()->findPk($giftCard->getId())?->getAmount());

        $this->postEditForm($edit + ['beneficiary_address' => '']);
        self::assertNull($recipient());
    }

    /**
     * The fragments the module adds to its configuration page (the table and the script that fills it),
     * and to the order page when an order id is given.
     *
     * @return array{string, string, string}
     */
    private function renderConfigurationScreen(string $locale, ?int $orderId = null, string $uri = '/admin/module/TheliaGiftCard', ?Admin $admin = null): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($admin ?? $this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang($locale));

        $request = Request::create($uri);
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

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function row(\DOMXPath $xpath, string $code): \DOMElement
    {
        $row = $xpath->query('//tr[@data-testid="datatable-gift-cards-row"][td[@data-testid="datatable-gift-cards-cell-code_html"]/div[1][normalize-space()="'.$code.'"]]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row, 'The list has no row for the card '.$code.'.');

        return $row;
    }

    /**
     * @return list<GiftCard>
     */
    private function allCards(): array
    {
        return array_values(iterator_to_array(GiftCardQuery::create()->find(), false));
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

    /**
     * @param array<string, string> $parameters
     */
    private function postAsAdmin(string $uri, array $parameters, bool $fromScript): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));
        $session->set((string) static::getContainer()->getParameter('thelia.token_id'), self::TOKEN);

        // The header jQuery adds to its requests.
        $request = Request::create($uri, 'POST', $parameters, server: $fromScript ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : []);
        $request->setSession($session);

        return $this->handleAsMainRequest($request);
    }

    /**
     * @param array<string, string> $fields
     */
    private function postCreateForm(array $fields): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));

        $request = Request::create('/admin/module/theliagiftcard/generate-gift-card', 'POST');
        $request->setSession($session);
        $request->request->set('manualy_create_gift_card', $fields + [
            'success_url' => '/admin/module/TheliaGiftCard',
            '_token' => $this->csrfToken($request, 'manualy_create_gift_card'),
        ]);

        return $this->handleAsMainRequest($request);
    }

    /**
     * @param array<string, string> $fields
     */
    private function postEditForm(array $fields): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));

        $request = Request::create('/admin/module/theliagiftcard/edit-gift-card', 'POST');
        $request->setSession($session);
        $request->request->set('edit_gift_card', $fields + [
            'success_url' => '/admin/module/TheliaGiftCard',
            '_token' => $this->csrfToken($request, 'edit_gift_card'),
        ]);

        return $this->handleAsMainRequest($request);
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
