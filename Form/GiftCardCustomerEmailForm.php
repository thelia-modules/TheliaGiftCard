<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardCustomerEmailForm extends BaseForm
{
    public static function getName(): string
    {
        return 'gift_card_customer_email';
    }

    protected function buildForm(): void
    {
        $this->formBuilder
            ->add(
                'gift_card_code',
                HiddenType::class,
                [
                    "required" => true,
                    "constraints" => [
                        new NotBlank()
                    ]
                ]
            )
            ->add(
                'email_subject',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_EMAIL_SUBJECT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_email_subject'
                    ],
                    "required" => false,
                ]
            )
            ->add(
                'email_text',
                TextareaType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_EMAIL_TEXT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_email_text'
                    ],
                    "required" => false,
                ]
            )
            ->add(
                'to',
                TextType::class,
                [
                    'label' => $this->translator->trans('Destinataire :', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_email_to'
                    ],
                    "required" => true,
                    "constraints" => [
                        new NotBlank()
                    ]
                ]
            );
    }
}