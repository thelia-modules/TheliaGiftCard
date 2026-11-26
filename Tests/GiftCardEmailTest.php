<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mime\Email;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardInfoCart;
use TheliaGiftCard\Service\GiftCardEmailService;

/**
 * The card as a PDF and by email, rendered from the Twig templates the module ships for the
 * default PDF and email themes. No mail leaves: the test environment uses the null transport,
 * whose messages the mailer logger keeps.
 */
final class GiftCardEmailTest extends GiftCardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigQuery::write('store_name', 'Test shop', false, true);
        ConfigQuery::write('store_email', 'shop@example.com', false, true);
    }

    public function testThePdfIsInTheRequestedLanguageAndCarriesTheCardDetails(): void
    {
        $giftCard = $this->cardWithDetails();

        $french = $this->service()->renderPdfHtml($giftCard->getCode(), 'fr_FR');
        $german = $this->service()->renderPdfHtml($giftCard->getCode(), 'de_DE');

        self::assertStringContainsString('Carte cadeau', $french);
        self::assertStringContainsString('De :', $french);
        self::assertStringContainsString('Geschenkkarte', $german);
        self::assertStringContainsString('Von:', $german);
        foreach ([$french, $german] as $html) {
            self::assertStringContainsString($giftCard->getCode(), $html);
            self::assertStringContainsString('Alice', $html);
            self::assertStringContainsString('Bob', $html);
            self::assertStringContainsString('Happy birthday', $html);
            self::assertStringNotContainsString('<script>', $html, 'What the buyer typed is escaped.');
        }
    }

    public function testThePdfOfACardWithNoNameTypedNamesThePeopleTheListShows(): void
    {
        $factory = $this->createFixtureFactory();
        $manual = $this->giftCard();
        self::assertStringContainsString('De : Test shop', $this->pdfPeople($manual), 'A card of the back office comes from the shop.');

        $activated = $this->giftCard(['beneficiaryCustomerId' => $factory->customer($factory->customerTitle(), ['firstname' => 'Camille', 'lastname' => 'Martin'])->getId()]);
        self::assertStringContainsString('Pour : Camille Martin', $this->pdfPeople($activated), 'The customer who activated it is the one it is for.');

        $bought = $this->giftCard();
        $bought->setSponsorCustomerId($factory->customer($factory->customerTitle(), ['firstname' => 'Paul', 'lastname' => 'Durand'])->getId())->save();
        self::assertStringContainsString('De : Paul Durand', $this->pdfPeople($bought), 'The buyer gives it.');

        self::assertStringContainsString('De : Alice', $this->pdfPeople($this->cardWithDetails()), 'A name typed comes first.');
    }

    public function testThePdfFollowsTheBackOfficeLanguageOfTheAdministrator(): void
    {
        $giftCard = $this->cardWithDetails();
        $this->currentSession()->set('thelia.current.admin_lang', $this->lang('de_DE'));

        self::assertStringContainsString('Geschenkkarte', $this->service()->renderPdfHtml($giftCard->getCode()));
    }

    public function testThePdfIsAPdf(): void
    {
        $pdf = $this->service()->generatePdfAction($this->cardWithDetails()->getCode(), 'fr_FR');

        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testTheEmailBodyDefaultsToTheTextOfTheEmailThemeInTheRequestedLanguage(): void
    {
        $french = $this->service()->generateGiftCardEmailHtmlContent(false, [], 'fr_FR');
        $german = $this->service()->generateGiftCardEmailHtmlContent(false, [], 'de_DE');

        self::assertStringContainsString('Bonjour,', $french);
        self::assertStringContainsString('Votre carte cadeau au format numérique', $french);
        self::assertStringContainsString('Hallo,', $german);
        self::assertStringContainsString('Ihre Geschenkkarte in digitaler Form', $german);
    }

    public function testTheEmailBodyKeepsTheTextTheAdministratorWrote(): void
    {
        $html = $this->service()->generateGiftCardEmailHtmlContent(false, ['email_subject' => 'Pour toi', 'email_text' => '<p>Texte du marchand</p>'], 'fr_FR');

        self::assertStringContainsString('<p>Texte du marchand</p>', $html);
        self::assertStringContainsString('Pour toi', $html);
        self::assertStringNotContainsString('Bonjour,', $html);
    }

    public function testTheSendWindowIsPrefilledWithTheDefaultSubjectAndText(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->createFixtureFactory()->admin());
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));
        $request = Request::create('/admin/module/TheliaGiftCard');
        $request->setSession($session);

        $response = $this->handleAsMainRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Votre carte cadeau au format numérique', (string) $response->getContent());
        self::assertStringContainsString('Veuillez trouver ci-jointe une carte cadeau à utiliser sur Test shop.', html_entity_decode((string) $response->getContent()));
    }

    public function testAnAdministratorWithoutRightsOnTheModuleCannotSendACard(): void
    {
        $giftCard = $this->cardWithDetails();
        $admin = $this->createFixtureFactory()->restrictedAdmin([]);

        $response = $this->postSendForm($admin, $giftCard->getCode(), 'someone@example.com');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sentEmails());
    }

    public function testAnAdministratorSendsTheCardAsAPdfAttachment(): void
    {
        $giftCard = $this->cardWithDetails();

        $response = $this->postSendForm($this->createFixtureFactory()->admin(), $giftCard->getCode(), 'someone@example.com');

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame('someone@example.com', $emails[0]->getTo()[0]->getAddress());
        self::assertSame($giftCard->getCode().'.pdf', $emails[0]->getAttachments()[0]->getFilename());
    }

    public function testTheRecipientMustBeAnEmailAddress(): void
    {
        $giftCard = $this->cardWithDetails();

        // Accepted by the mailer (RFC 5322), refused by the form: nothing else stops it.
        $this->postSendForm($this->createFixtureFactory()->admin(), $giftCard->getCode(), 'someone@localhost');

        self::assertSame([], $this->sentEmails());
    }

    private function cardWithDetails(): GiftCard
    {
        $giftCard = $this->giftCard(['amount' => '80']);

        (new GiftCardInfoCart())
            ->setGiftCardId($giftCard->getId())
            ->setSponsorName('Alice')
            ->setBeneficiaryName('Bob')
            ->setBeneficiaryMessage("Happy birthday\n<script>alert(1)</script>")
            ->save();

        return $giftCard;
    }

    /**
     * The block of the PDF that names the giver and the recipient, without its tags.
     */
    private function pdfPeople(GiftCard $giftCard): string
    {
        $html = $this->service()->renderPdfHtml($giftCard->getCode(), 'fr_FR');

        return (string) preg_replace('/\s+/', ' ', strip_tags($html));
    }

    private function service(): GiftCardEmailService
    {
        return $this->getService(GiftCardEmailService::class);
    }

    private function postSendForm(Admin $admin, string $code, string $recipient): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($admin);
        $session->set('thelia.current.admin_lang', $this->lang('fr_FR'));

        $request = Request::create('/admin/module/theliagiftcard/giftcard/send', 'POST');
        $request->setSession($session);
        $request->request->set('gift_card_customer_email', [
            'gift_card_code' => $code,
            'to' => $recipient,
            'email_subject' => '',
            'email_text' => '',
            'success_url' => '/admin/module/TheliaGiftCard',
            'error_url' => '/admin/module/TheliaGiftCard',
            '_token' => $this->csrfToken($request, 'gift_card_customer_email'),
        ]);

        return $this->handleAsMainRequest($request);
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email));
    }

    private function currentSession(): Session
    {
        $session = $this->requestStack()->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
