<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

Abstract class BaseGiftCardForm extends BaseForm
{
    public static function getName(): string
    {
        return 'base_gift_card_form';
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
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'beneficiary_name',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_NAME', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'beneficiary_message',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_MESSAGE', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'beneficiary_email',
                EmailType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_MESSAGE', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'beneficiary_address',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_BENEFICIARY_ADDRESS', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ]);
    }
}