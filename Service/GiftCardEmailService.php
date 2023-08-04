<?php

namespace TheliaGiftCard\Service;

use Exception;
use SmartyException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Mailer\MailerFactory;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardEmailService
{
    public function __construct(
        protected TemplateHelperInterface  $templateHelper,
        protected RequestStack             $requestStack,
        protected EventDispatcherInterface $dispatcher,
        protected GiftCardService          $giftCardService,
        protected ParserInterface          $parser,
        protected MailerFactory            $mailer,
    )
    {
    }

    /**
     * @param string $code
     * @throws Exception
     */
    public function generatePdfAction(string $code): string
    {
        $request = $this->requestStack->getCurrentRequest();

        try {
            $infos = $this->giftCardService->getInfoGiftCard($code);

            $this->parser->setTemplateDefinition(
                $this->templateHelper->getActivePdfTemplate(),
                true
            );

            $html = $this->parser->render(
                'giftCard.html',
                [
                    'message' => $infos['message'],
                    'code' => $infos['code'],
                    'SNAME' => $infos['sponsorName'],
                    'BNAME' => $infos['beneficiaryName'],
                    'AMOUNT' => $infos['amount'],
                    'default_locale' => $request->getSession()->getAdminLang()->getLocale()
                ]
            );

            $pdfEvent = new PdfEvent($html);
            $this->dispatcher->dispatch($pdfEvent, TheliaEvents::GENERATE_PDF);

            return $pdfEvent->getPdf();
        } catch (Exception $e) {
            throw new Exception(
                Translator::getInstance()->trans(
                    'Error on gift card pdf generation : ' . $e->getMessage()),
                [],
                TheliaGiftCard::DOMAIN_NAME
            );
        }
    }

    /**
     * @param bool $toBeneficiary
     * @param array $data
     * @return string
     * @throws SmartyException
     */
    public function generateGiftCardEmailHtmlContent(bool $toBeneficiary = false, array $data = []): string
    {
        $this->parser->setTemplateDefinition(
            $this->templateHelper->getActiveMailTemplate(),
            true
        );

        return $this->parser->render(
            (false === $toBeneficiary) ? "gift_card_customer_notification.html" : "gift_card_beneficiary_notification.html",
            [
                "email_subject" => (isset($data["email_subject"])) ?
                    $data["email_subject"]
                    :
                    Translator::getInstance()->trans(
                        "Votre carte cadeau au format numérique.",
                        [],
                        TheliaGiftCard::DOMAIN_NAME
                    ),
                "email_text" => $data["email_text"] ?? ''
            ],
            $this->templateHelper->getActiveMailTemplate()
        );
    }
}