<?php

namespace TheliaGiftCard\Form;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Symfony\Component\Validator\Constraints;
use Thelia\Model\Customer;
use Thelia\Model\Map\ProductI18nTableMap;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\TheliaGiftCard;

class ConsumeGiftCart extends BaseForm
{
    protected function buildForm(): void
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
                ChoiceType::class,
                [
                    'label' => Translator::getInstance()->trans('FORM_LIST_CARD_GIFT', [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => [
                        'for' => 'gift_card_code'
                    ],
                    "required"    => true,
                    "constraints" => [
                        new Constraints\NotBlank(),
                    ],
                    "choices" => $this->getGiftCardCodes(),
                    "multiple" => true
                ]
            )
        ;
    }

    protected function getGiftCardCodes():array
    {
        /** @var Customer $customer */
        if(!$customer = $this->getRequest()->getSession()->getCustomerUser()){
            return [];
        }

        return GiftCardQuery::create()
            ->select(GiftCardTableMap::COL_CODE)
            ->filterByBeneficiaryCustomerId($customer->getId())
            ->filterByStatus(1)
            ->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT)
            ->find()->toArray();
    }

    public static function getName(): string
    {
        return "consume_gift_card";
    }
}