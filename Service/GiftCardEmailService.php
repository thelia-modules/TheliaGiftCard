<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaGiftCard\Exception\GiftCardNotFoundException;

/**
 * Renders a gift card as a PDF and sends it by email, from the Twig templates of the active PDF
 * and email themes. The module ships generic templates for the "default" themes; a shop
 * overrides any of them by putting a file of the same name in its own theme.
 *
 * Everything is rendered in one language, the back-office language of the administrator who
 * sends the card unless another one is asked for: the templates receive it as
 * `gift_card_locale` (the parser forces its own `locale` variable to the session language).
 */
final readonly class GiftCardEmailService
{
    public const PDF_TEMPLATE = 'giftCard';

    public const CUSTOMER_EMAIL_TEMPLATE = 'gift_card_customer_notification';

    public const BENEFICIARY_EMAIL_TEMPLATE = 'gift_card_beneficiary_notification';

    public const DEFAULT_TEXT_TEMPLATE = 'gift_card_email_text';

    public const EMAIL_DOMAIN = 'theliagiftcard.email.default';

    public function __construct(
        private TemplateHelperInterface $templateHelper,
        private RequestStack $requestStack,
        private EventDispatcherInterface $dispatcher,
        private GiftCardService $giftCardService,
        private ParserResolver $parserResolver,
        private MailerFactory $mailer,
    ) {
    }

    /**
     * @throws GiftCardNotFoundException when no card carries the code
     */
    public function generatePdfAction(string $code, ?string $locale = null): string
    {
        $pdfEvent = new PdfEvent($this->renderPdfHtml($code, $locale));
        $pdfEvent->setTemplateName(self::PDF_TEMPLATE);
        $pdfEvent->setFileName($code);
        $this->dispatcher->dispatch($pdfEvent, TheliaEvents::GENERATE_PDF);

        if (!$pdfEvent->hasPdf()) {
            throw new \RuntimeException('No PDF renderer answered for the gift card.');
        }

        return $pdfEvent->getPdf();
    }

    /**
     * The HTML the PDF is made from.
     *
     * @throws GiftCardNotFoundException when no card carries the code
     */
    public function renderPdfHtml(string $code, ?string $locale = null): string
    {
        $infos = $this->giftCardService->getInfoGiftCard($code);

        if (null === $infos) {
            throw new GiftCardNotFoundException('No gift card for the requested code.');
        }

        return $this->render($this->templateHelper->getActivePdfTemplate(), self::PDF_TEMPLATE, self::PDF_TEMPLATE, [
            'gift_card_locale' => $this->resolveLocale($locale),
            'code' => (string) $infos['code'],
            'amount' => (float) $infos['amount'],
            // Cards are worth an amount of the default currency; given explicitly, the format
            // does not depend on a currency in session, absent from the command line.
            'currency_id' => Currency::getDefaultCurrency()->getId(),
            'expiration_date' => $infos['expirationDate'],
            'sponsor_name' => (string) $infos['sponsorName'],
            'beneficiary_name' => (string) $infos['beneficiaryName'],
            'message' => (string) $infos['message'],
            'store_name' => (string) ConfigQuery::getStoreName(),
        ]);
    }

    /**
     * @param array{email_subject?: ?string, email_text?: ?string} $data
     */
    public function generateGiftCardEmailHtmlContent(bool $toBeneficiary = false, array $data = [], ?string $locale = null): string
    {
        $locale = $this->resolveLocale($locale);
        $template = $toBeneficiary ? self::BENEFICIARY_EMAIL_TEMPLATE : self::CUSTOMER_EMAIL_TEMPLATE;
        $text = trim((string) ($data['email_text'] ?? ''));
        $subject = trim((string) ($data['email_subject'] ?? ''));

        return $this->render($this->templateHelper->getActiveMailTemplate(), $template, $template.'.html', [
            'gift_card_locale' => $locale,
            'email_subject' => '' !== $subject ? $subject : $this->defaultEmailSubject($locale),
            'email_text' => '' !== $text ? $text : $this->renderDefaultEmailText($locale),
        ]);
    }

    /**
     * The body proposed to the administrator in the "send by email" window, and sent when the
     * field is left empty.
     */
    public function renderDefaultEmailText(?string $locale = null): string
    {
        return trim($this->render($this->templateHelper->getActiveMailTemplate(), self::DEFAULT_TEXT_TEMPLATE, self::DEFAULT_TEXT_TEMPLATE.'.html', [
            'gift_card_locale' => $this->resolveLocale($locale),
            'store_name' => (string) ConfigQuery::getStoreName(),
            'store_email' => (string) ConfigQuery::getStoreEmail(),
            'store_url' => (string) ConfigQuery::read('url_site', ''),
        ]));
    }

    public function defaultEmailSubject(?string $locale = null): string
    {
        return Translator::getInstance()->trans('Your gift card in digital format', [], self::EMAIL_DOMAIN, $this->resolveLocale($locale));
    }

    /**
     * Sends the card as a PDF attachment to the address the administrator typed.
     *
     * @throws GiftCardNotFoundException when no card carries the code
     */
    public function sendByEmail(string $code, string $recipient, ?string $subject = null, ?string $text = null, ?string $locale = null): void
    {
        $locale = $this->resolveLocale($locale);
        $subject = trim((string) $subject);
        $pdf = $this->generatePdfAction($code, $locale);

        $message = $this->mailer->createSimpleEmailMessage(
            [(string) ConfigQuery::getStoreEmail() => (string) ConfigQuery::getStoreName()],
            [$recipient => $recipient],
            '' !== $subject ? $subject : $this->defaultEmailSubject($locale),
            $this->generateGiftCardEmailHtmlContent(false, ['email_subject' => $subject, 'email_text' => $text], $locale),
            '',
        );

        $message->attach($pdf, $code.'.pdf', 'application/pdf');

        $this->mailer->send($message);
    }

    /**
     * An explicit locale wins when the shop has that language, then the back-office language
     * of the administrator, then the default language.
     */
    public function resolveLocale(?string $locale = null): string
    {
        if (null !== $locale && '' !== $locale && null !== LangQuery::create()->findOneByLocale($locale)) {
            return $locale;
        }

        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof Session && $session->getAdminLang() instanceof Lang) {
            return $session->getAdminLang()->getLocale();
        }

        return Lang::getDefaultLanguage()->getLocale();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(TemplateDefinition $templateDefinition, string $viewName, string $fileName, array $parameters): string
    {
        $parser = $this->parserResolver->getParser($templateDefinition->getAbsolutePath(), $viewName);
        $previousDefinition = $parser->getTemplateDefinition();
        $parser->setTemplateDefinition($templateDefinition, true);

        try {
            return $parser->render($fileName, $parameters);
        } finally {
            // The parser is shared: the back-office page being rendered around the send window
            // keeps its own theme.
            if ($previousDefinition instanceof TemplateDefinition) {
                $parser->setTemplateDefinition($previousDefinition, true);
            }
        }
    }
}
