<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form\Config;

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use TheliaGiftCard\Form\BaseGiftCardForm;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Class ManualyCreateGiftCard
 */
class ManualyCreateGiftCard extends BaseGiftCardForm
{
    /**
     * @return string
     */
    public static function getName(): string
    {
        return 'manualy_create_gift_card';
    }

    /**
     * @return void|null
     */
    protected function buildForm()
    {
        parent::buildForm();

        $this->formBuilder->add(
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