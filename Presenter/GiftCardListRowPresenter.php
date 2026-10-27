<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Presenter;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\LangQuery;
use TheliaGiftCard\Model\GiftCard;

/**
 * A card as a row of the back-office list, shaped for the BoDataTable of the module page. The
 * actions are built by the template (row_action()); the row carries what they need: the PDF
 * links, and the address that activates or deactivates the card.
 */
final readonly class GiftCardListRowPresenter
{
    private const DOMAIN = 'theliagiftcard.bo.default-twig';

    // Every address goes through the router, so a shop installed in a sub-folder keeps working links.
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<GiftCard>                                                                                                                                                                                                                                                                               $giftCards
     * @param array{orders: array<int, string>, sponsor_customers: array<int, array{name: string, email: string, country: string|null}>, beneficiary_customers: array<int, array{name: string, email: string, country: string|null}>, languages: list<array{locale: string, code: string, title: string}>} $info
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $giftCards, array $info, string $locale): array
    {
        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $currencyCode = (string) Currency::getDefaultCurrency()->getCode();
        $storeName = (string) ConfigQuery::read('store_name', '');
        // The date as the language of the interface writes it (d/m/Y in French, d.m.Y in German).
        $dateFormat = (string) (LangQuery::create()->findOneByLocale($locale)?->getDateFormat() ?: 'Y-m-d');

        return array_map(
            fn (GiftCard $giftCard): array => $this->present($giftCard, $info, $money, $currencyCode, $storeName, $dateFormat),
            $giftCards,
        );
    }

    /**
     * @param array{orders: array<int, string>, sponsor_customers: array<int, array{name: string, email: string, country: string|null}>, beneficiary_customers: array<int, array{name: string, email: string, country: string|null}>, languages: list<array{locale: string, code: string, title: string}>} $info
     *
     * @return array<string, mixed>
     */
    private function present(GiftCard $giftCard, array $info, \NumberFormatter $money, string $currencyCode, string $storeName, string $dateFormat): array
    {
        $code = (string) $giftCard->getCode();
        $isActive = 1 === (int) $giftCard->getStatus();
        $orderId = $giftCard->getOrderId();
        $sponsorId = $giftCard->getSponsorCustomerId();
        $beneficiaryId = $giftCard->getBeneficiaryCustomerId();

        return [
            'id' => (int) $giftCard->getId(),
            'code' => $code,
            // The code, and the order the card was bought with under it, as the order list puts the email under a name.
            'code_html' => '<div class="fw-semibold">'.htmlspecialchars($code).'</div><div class="small">'
                .$this->orderCell($orderId, $info['orders'][$orderId ?? 0] ?? '').'</div>',
            'sponsor_html' => null !== $sponsorId
                ? $this->customerCell($sponsorId, $info['sponsor_customers'][$sponsorId] ?? null)
                : '<div class="fw-semibold">'.htmlspecialchars($storeName).'</div>',
            'beneficiary_html' => null !== $beneficiaryId
                ? $this->customerCell($beneficiaryId, $info['beneficiary_customers'][$beneficiaryId] ?? null)
                : '<span class="text-muted">'.htmlspecialchars($this->translator->trans('Not used', [], self::DOMAIN)).'</span>',
            'amount_html' => $this->amountCell(
                (string) $money->formatCurrency((float) $giftCard->getAmount(), $currencyCode),
                (string) $money->formatCurrency((float) $giftCard->getSpendAmount(), $currencyCode),
            ),
            'expiration_date' => (string) $giftCard->getExpirationDate($dateFormat),
            'status' => $isActive ? 1 : 0,
            'status_label' => $this->translator->trans($isActive ? 'Active' : 'Not active', [], self::DOMAIN),
            // The code goes in the body of the form, not in an address that ends up in logs and history.
            'status_url' => $this->urls->generate($isActive ? 'deactivate_gift_card' : 'activate_gift_card'),
            'status_action_label' => $this->translator->trans($isActive ? 'Deactivate_GIFT_CARD' : 'Activate_GIFT_CARD', [], self::DOMAIN),
            // A card that is not active is neither sent nor printed.
            'pdf_html' => $isActive ? $this->pdfCell($code, $info['languages']) : '',
        ];
    }

    /**
     * The order of a card bought in the shop, linked, or a short mention for the card of an administrator.
     */
    private function orderCell(?int $orderId, string $reference): string
    {
        if (null === $orderId) {
            return '<span class="text-muted">'.htmlspecialchars($this->translator->trans('Manual card', [], self::DOMAIN)).'</span>';
        }

        return \sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($this->urls->generate('admin.order.update.view', ['order_id' => $orderId])),
            htmlspecialchars('' !== $reference ? $reference : '#'.$orderId),
        );
    }

    /**
     * A customer as the order list shows one: the flag of their country and their name, linked to their account,
     * their email under it.
     * Everything in it comes from the customer account and is escaped.
     *
     * @param array{name: string, email: string, country: string|null}|null $customer
     */
    private function customerCell(int $customerId, ?array $customer): string
    {
        if (null === $customer) {
            return '';
        }

        // The flag comes from the back-office theme; without it the name stands alone.
        $flag = class_exists(CountryFlag::class) ? CountryFlag::prefix($customer['country']) : '';

        return \sprintf(
            '<div class="bo-order-customer" style="max-width: 9.5rem"><div class="fw-semibold text-wrap">%s<a class="text-decoration-none" href="%s">%s</a></div><div class="text-muted small text-truncate">%s</div></div>',
            $flag,
            htmlspecialchars($this->urls->generate('admin.customer.update.view', ['customer_id' => $customerId])),
            htmlspecialchars($customer['name']),
            htmlspecialchars($customer['email']),
        );
    }

    /**
     * The amount of the card, what was spent of it under it. Both read left to right in every language.
     */
    private function amountCell(string $amount, string $spent): string
    {
        return \sprintf(
            '<div class="fw-semibold"><span dir="ltr">%s</span></div><div class="text-muted small">%s <span dir="ltr">%s</span></div>',
            htmlspecialchars($amount),
            htmlspecialchars($this->translator->trans('Spent', [], self::DOMAIN)),
            htmlspecialchars($spent),
        );
    }

    /**
     * One button per active language, marked by its flag, the PDF of the card written in it.
     *
     * @param list<array{locale: string, code: string, title: string}> $languages
     */
    private function pdfCell(string $code, array $languages): string
    {
        $buttons = array_map(
            fn (array $language): string => \sprintf(
                '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
                htmlspecialchars($this->urls->generate('config_send_pdf', ['l' => $language['locale'], 'code' => $code])),
                htmlspecialchars($this->translator->trans('PDF (%language%)', ['%language%' => $language['title']], self::DOMAIN)),
                $this->languageLabel($language['locale'], $language['code']),
            ),
            $languages,
        );

        return '<div class="btn-group" role="group">'.implode('', $buttons).'</div>';
    }

    /**
     * The flag of the country of a locale (fr_FR, de_DE), the button title naming the language; the code of the
     * language when the locale has no country or the theme no flags.
     */
    private function languageLabel(string $locale, string $code): string
    {
        $country = strrchr($locale, '_');
        $flag = false !== $country && class_exists(CountryFlag::class) ? CountryFlag::of(substr($country, 1)) : '';

        return '' !== $flag ? $flag : htmlspecialchars(strtoupper($code));
    }
}
