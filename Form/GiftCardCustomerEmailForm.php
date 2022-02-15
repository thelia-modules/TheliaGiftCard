<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardCustomerEmailForm extends BaseForm
{
    public static function getName()
    {
        return 'gift_card_customer_email';
    }

    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                'status_id',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_EMAIL_STATUS_ID', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_status_id'
                    ]
                ])
            ->add(
                'email_subject',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_EMAIL_SUBJECT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_email_subject'
                    ]
                ])
            ->add(
                'email_text',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_EMAIL_TEXT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'giftcard_email_text'
                    ]
                ])
        ;

    }
}