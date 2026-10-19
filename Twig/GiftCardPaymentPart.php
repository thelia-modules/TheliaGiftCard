<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Twig;

use FlexyBundle\Event\CheckoutEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Customer;
use TheliaGiftCard\Dto\SpendableGiftCard;
use TheliaGiftCard\Exception\GiftCardSpendRefusedException;
use TheliaGiftCard\Exception\GiftCardTooManyCodeAttemptsException;
use TheliaGiftCard\Service\GiftCardCartSelection;
use TheliaGiftCard\Service\GiftCardCartSpending;

/**
 * The gift cards of the buyer on the payment step: tick a card, type the code of one, take them
 * off. Every change is written on the cart by the module, then the step is told to read the
 * payment composition again (CheckoutEvents::PAYMENT_PARTS_CHANGED) and the summary to draw
 * itself again (`syncSummary`, the only event besides the cart's own that the summary listens to).
 *
 * A live action only answers a request that carries `X-Requested-With: XMLHttpRequest` (the
 * LiveComponentSubscriber ignores any other), which a cross-site form cannot send: that is the
 * protection against CSRF. The cart is the one of the session, the cards the ones of the
 * signed-in customer: nothing a request carries names either of them.
 */
#[AsLiveComponent(name: 'TheliaGiftCard:PaymentPart', template: '@TheliaGiftCardModule/frontOffice/flexy/TheliaGiftCard/components/payment-part.html.twig')]
final class GiftCardPaymentPart
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * What the theme says the cards pay, a decimal string. Not read here: a prop the parent
     * changes is what makes this component draw itself again when the step changed without it.
     */
    #[LiveProp(updateFromParent: true)]
    public string $appliedAmount = '0';

    /** The code the buyer is typing. */
    #[LiveProp(writable: true)]
    public string $code = '';

    /**
     * Why the last action was refused, a code the template turns into a sentence: `code`,
     * `attempts` or `refused`. Not live state: gone with the next action.
     */
    public ?string $error = null;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly SecurityContext $securityContext,
        private readonly RequestStack $requestStack,
        private readonly LangService $langService,
        private readonly GiftCardCartSpending $cartSpending,
        private readonly GiftCardCartSelection $cartSelection,
    ) {
    }

    /**
     * @return list<SpendableGiftCard>
     */
    public function getCards(): array
    {
        $customer = $this->customer();

        return null === $customer ? [] : $this->cartSpending->spendableCards($customer, $this->cartFacade->getOrCreateFromSession());
    }

    /**
     * Whether any card is on the cart.
     */
    public function getHasCardsOnCart(): bool
    {
        foreach ($this->getCards() as $card) {
            if ('0.00' !== $card->amountOnCart) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language a template formats amounts and dates with: lang_code is a variable of the
     * page's render, not of a live component's own.
     */
    public function getLangCode(): string
    {
        return $this->langService->getLang()?->getCode() ?? 'en';
    }

    public function getCurrencyCode(): string
    {
        return $this->cartFacade->getOrCreateFromSession()->getCurrency()?->getCode() ?? 'EUR';
    }

    /**
     * Whether the buyer can use gift cards here at all: a signed-in customer, not a guest.
     */
    public function getAvailable(): bool
    {
        return null !== $this->customer();
    }

    #[LiveAction]
    public function toggle(#[LiveArg] int $giftCardId = 0): void
    {
        $this->error = null;
        $customer = $this->customer();

        if (null === $customer) {
            return;
        }

        try {
            $this->cartSelection->toggle($customer, $this->cartFacade->getOrCreateFromSession(), $giftCardId);
        } catch (GiftCardSpendRefusedException) {
            $this->error = 'refused';
        }

        $this->tellTheStep();
    }

    #[LiveAction]
    public function applyCode(): void
    {
        $this->error = null;
        $customer = $this->customer();

        if (null === $customer) {
            return;
        }

        try {
            $this->cartSelection->applyCode(
                $customer,
                $this->cartFacade->getOrCreateFromSession(),
                $this->code,
                $this->requestStack->getCurrentRequest()?->getClientIp(),
            );
            $this->code = '';
        } catch (GiftCardTooManyCodeAttemptsException) {
            $this->error = 'attempts';
        } catch (GiftCardSpendRefusedException $refusal) {
            $this->error = GiftCardSpendRefusedException::CODE_NOT_USABLE === $refusal->getCode() ? 'code' : 'refused';
        }

        $this->tellTheStep();
    }

    #[LiveAction]
    public function remove(): void
    {
        $this->error = null;

        if (null === $this->customer()) {
            return;
        }

        $this->cartSelection->clear($this->cartFacade->getOrCreateFromSession());

        $this->tellTheStep();
    }

    /**
     * The step reads the payment composition again, and the summary, which does not listen to
     * PAYMENT_PARTS_CHANGED, reads the cards and the discount of the cart again.
     */
    private function tellTheStep(): void
    {
        $this->emit(CheckoutEvents::PAYMENT_PARTS_CHANGED);
        $this->emit('syncSummary');
    }

    private function customer(): ?Customer
    {
        $customer = $this->securityContext->getCustomerUser();

        return $customer instanceof Customer && !$customer->isGuest() ? $customer : null;
    }
}
