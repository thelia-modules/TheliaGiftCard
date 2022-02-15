<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form\Config;

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

class ManualyCreateGiftCard extends BaseForm
{
    public static function getName()
    {
        return 'info_add_card_gift';
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
                'amount',
                NumberType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_AMOUNT_GC', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'expiration_date',
                DateType::class,
                [
                    'widget' => 'single_text',
                    'label' => $this->translator->trans('FORM_ADD_EXPIRATION_GC', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ]);
    }
}