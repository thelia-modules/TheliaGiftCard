<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Form\BaseForm;
use Thelia\Model\Customer;
use TheliaGiftCard\Service\GiftCardCartSpending;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The cards the customer puts on their cart at checkout, and the amount they want to pay with them.
 *
 * The cards are chosen by id among the ones the customer can spend on this cart: a card of
 * another customer, disabled, expired or spent out is not a valid choice. The amount is kept
 * as typed, a decimal string, and capped by GiftCardCartSpending::spend().
 */
class ConsumeGiftCart extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
            ->add(
                'gift_card_ids',
                ChoiceType::class,
                [
                    'label' => $this->translator->trans('FORM_LIST_CARD_GIFT', [], TheliaGiftCard::DOMAIN_NAME),
                    'choices' => $this->spendableCardIds(),
                    'multiple' => true,
                    'expanded' => true,
                    'required' => false,
                ]
            )
            ->add(
                'amount_used',
                NumberType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_AMOUNT_CARD_GIFT', [], TheliaGiftCard::DOMAIN_NAME),
                    'input' => 'string',
                    'scale' => 2,
                    'required' => true,
                    'constraints' => [
                        new Constraints\NotBlank(),
                        new Constraints\PositiveOrZero(),
                    ],
                ]
            );
    }

    /**
     * @return array<string, int> the cards the customer can spend on the session cart, by id
     */
    private function spendableCardIds(): array
    {
        $request = $this->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;

        if (!$session instanceof Session || !($customer = $session->getCustomerUser()) instanceof Customer) {
            return [];
        }

        $choices = [];

        foreach ((new GiftCardCartSpending($this->dispatcher))->spendableCards($customer, $session->getSessionCart($this->dispatcher)) as $card) {
            $choices[(string) $card->id] = $card->id;
        }

        return $choices;
    }

    public static function getName(): string
    {
        return 'consume_gift_card';
    }
}
