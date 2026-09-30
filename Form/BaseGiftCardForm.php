<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

abstract class BaseGiftCardForm extends BaseForm
{
    /**
     * Sizes of the gift_card_info_cart columns the fields are stored in.
     */
    private const NAME_MAX_LENGTH = 250;

    private const TEXT_MAX_LENGTH = 500;

    public static function getName(): string
    {
        return 'base_gift_card_form';
    }

    /**
     * Whether the sponsor and beneficiary names must be filled: they are printed on the card.
     */
    protected function requiresNames(): bool
    {
        return false;
    }

    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                'sponsor_name',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_SPONSOR_NAME', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName().'-label',
                    ],
                    'constraints' => $this->nameConstraints(),
                ]
            )
            ->add(
                'beneficiary_name',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_NAME', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName().'-label',
                    ],
                    'constraints' => $this->nameConstraints(),
                ]
            )
            ->add(
                'beneficiary_message',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_MESSAGE', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName().'-label',
                    ],
                    'constraints' => [new Length(max: self::TEXT_MAX_LENGTH)],
                ]
            )
            ->add(
                'beneficiary_email',
                EmailType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_MESSAGE', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName().'-label',
                    ],
                    'constraints' => [new Email(), new Length(max: self::TEXT_MAX_LENGTH)],
                ]
            )
            ->add(
                'beneficiary_address',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_ADDRESS', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName().'-label',
                    ],
                    'constraints' => [new Length(max: self::TEXT_MAX_LENGTH)],
                ]
            );
    }

    /**
     * @return list<Constraint>
     */
    private function nameConstraints(): array
    {
        $constraints = [new Length(max: self::NAME_MAX_LENGTH)];

        if ($this->requiresNames()) {
            $constraints[] = new NotBlank();
        }

        return $constraints;
    }
}
