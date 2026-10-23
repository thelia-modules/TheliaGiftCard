<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Hook\Theme;

use Thelia\Core\Hook\Theme\ThemeHookInterface;
use TheliaGiftCard\TheliaGiftCard;
use Twig\Environment;

/**
 * The gift-card line of the "Pay part of your order with" block of the payment step.
 *
 * The theme calls checkout.payment.parts once per instant payment module, with the same name:
 * only the call that names this module draws something.
 */
final readonly class GiftCardPaymentPartThemeHook implements ThemeHookInterface
{
    private const string HOOK = 'checkout.payment.parts';

    private const string TEMPLATE = '@TheliaGiftCardModule/frontOffice/flexy/TheliaGiftCard/checkout-payment-part.html.twig';

    public function __construct(
        private Environment $twig,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return self::HOOK === $hookName;
    }

    /**
     * @param array{module?: int|string, amount?: string} $parameters
     */
    public function render(string $hookName, array $parameters): string
    {
        if ((int) ($parameters['module'] ?? 0) !== TheliaGiftCard::getModuleId()) {
            return '';
        }

        return $this->twig->render(self::TEMPLATE, [
            'amount' => (string) ($parameters['amount'] ?? '0'),
        ]);
    }
}
