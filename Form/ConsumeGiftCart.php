<?php

namespace TheliaGiftCard\Form;

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Symfony\Component\Validator\Constraints;
use TheliaGiftCard\TheliaGiftCard;

class ConsumeGiftCart extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                "amount_used",
                NumberType::class,
                [
                    'label' => Translator::getInstance()->trans('FORM_ADD_AMOUNT_CARD_GIFT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'amount_used'
                    ],
                    "required"    => true,
                    "constraints" => [
                        new Constraints\NotBlank(),
                    ]
                ]
            )
            ->add(
                'delivery_module_id',
                IntegerType::class)
            ->add(
                "gift_card_codes",
                TextType::class,
                [
                    'label' => Translator::getInstance()->trans('FORM_LIST_CARD_GIFT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'gift_card_code'
                    ],
                    "required"    => true,
                    "constraints" => [
                        new Constraints\NotBlank(),
                    ]
                ]
            )
        ;
    }

    public static function getName()
    {
        return "consume_gift_card";
    }
}