<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use TheliaGiftCard\Form\Config\ManualyEditGiftCard;
use TheliaGiftCard\TheliaGiftCard;

class BuyCustomGiftCardForm extends BaseGiftCardForm
{
    /**
     * @return string
     */
    public static function getName(): string
    {
        return 'save_gift_card_info';
    }

    /**
     * @return void|null
     */
    protected function buildForm()
    {
        parent::buildForm();

        $this->formBuilder
            ->add(
                'product_id',
                TextType::class,
                [
                    'label' => $this->translator->trans('FORM_ADD_SPONSOR_NAME', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => $this->getName() . '-label'
                    ]
                ]);

    }
}