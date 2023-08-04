<?php

namespace TheliaGiftCard\Form\Config;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Symfony\Component\Validator\Constraints as Assert;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\OrderStatusQuery;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardConfigForm extends BaseForm
{
    protected int $selectedGiftCardCategory;
    protected int $selectedGiftCardOrderStatus;
    protected bool $selectedGiftCardMode;

    /**
     * @return void|null
     */
    protected function buildForm()
    {
        $this->selectedGiftCardCategory = TheliaGiftCard::getGiftCardCategoryId();
        $this->selectedGiftCardOrderStatus = TheliaGiftCard::getGiftCardOrderStatusId();
        $this->selectedGiftCardMode = TheliaGiftCard::isAutoSendEmail();

        $this->formBuilder
            ->add(
                'gift_card_category',
                ChoiceType::class, [
                    'label' => Translator::getInstance()->trans("Category where the gift card are located", [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => array(
                        'for' => 'sample_category'
                    ),
                    'choices' => $this->getAllCategories(),
                    'data' => $this->selectedGiftCardCategory,
                    'constraints' => [
                        new Assert\NotBlank
                    ],
                ]
            )
            ->add(
                'gift_card_paid_status',
                ChoiceType::class, [
                    'label' => Translator::getInstance()->trans("Order Status where order is paid", [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => array(
                        'for' => 'sample_category'
                    ),
                    'choices' => $this->getAllOrderStatus(),
                    'data' => $this->selectedGiftCardOrderStatus,
                    'constraints' => [
                        new Assert\NotBlank
                    ],
                ]
            )
            ->add(
                'gift_card_auto_send',
                CheckboxType::class, [
                    'label' => Translator::getInstance()->trans("Activate auto send gift card by email", [], TheliaGiftCard::DOMAIN_NAME),
                    'label_attr' => array(
                        'for' => 'gift_card_auto_send'
                    ),
                    'required' => false
                ]
            );
    }

    public function getAllCategories(): array
    {
        /** @var Request $request */
        $request = $this->request;

        $lang = $request->getSession()?->getAdminEditionLang();

        $categories = CategoryQuery::create()
            ->joinWithI18n($lang->getLocale(), Criteria::INNER_JOIN)
            ->find();

        $tabData = [];

        /** @var Category $category */
        foreach ($categories as $category) {
            $tabData[$category->getTitle()] = $category->getId();
        }

        return $tabData;
    }

    /**
     * @return array
     */
    public function getAllOrderStatus(): array
    {
        /** @var Request $request */
        $request = $this->request;

        $lang = $request->getSession()?->getAdminEditionLang();

        $ordersStatus = OrderStatusQuery::create()
            ->joinWithI18n($lang->getLocale(), Criteria::INNER_JOIN)
            ->find();

        $tabData = [];

        /** @var Category $category */
        foreach ($ordersStatus as $orderStatus) {
            $tabData[$orderStatus->getTitle()] = $orderStatus->getId();
        }

        return $tabData;
    }

    public static function getName(): string
    {
        return "gift_card_config";
    }
}
