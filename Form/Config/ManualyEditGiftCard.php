<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form\Config;

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Form\BaseForm;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Class ManualyEditGiftCard
 */
class ManualyEditGiftCard extends BaseForm
{
    /**
     * @return string
     */
    public static function getName(): string
    {
        return 'edit_gift_card';
    }

    /**
     * @return void|null
     */
    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                'gift_card_id',
                NumberType::class,
                [
                    'label' => $this->translator->trans('FORM_EDIT_ID_GC', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ],
                    'constraints' => [new NotBlank()]
                ])
            ->add(
                'amount',
                NumberType::class,
                [
                    'label' => $this->translator->trans('FORM_EDIT_AMOUNT_GC', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'expiration_date',
                DateType::class,
                [
                    'widget' => 'single_text',
                    'label' => $this->translator->trans('FORM_EDIT_EXPIRATION_GC', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ])
            ->add(
                'beneficiary_address',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_EDIT_BENEFICIARY_ADDRESS', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ]);
    }
}