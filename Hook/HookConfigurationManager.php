<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Hook;

use Propel\Runtime\Exception\PropelException;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use TheliaGiftCard\TheliaGiftCard;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Model\GiftCardQuery;
use Thelia\Model\LangQuery;
use Thelia\Core\Translation\Translator;

class HookConfigurationManager extends BaseHook
{
    /**
     * @throws PropelException
     */
    public function onConfiguration(HookRenderEvent $event): void
    {
        $giftCards = GiftCardQuery::create()->find();
        $allInfo = array(
            'orders' => array(),
            'sponsor_customers' => array(),
            'beneficiary_customers' => array(),
            'languages' => array()
        );

        foreach ($giftCards as $giftCard) {
            $beneficiaryCustomer = $giftCard->getCustomerRelatedByBeneficiaryCustomerId();
            if ($beneficiaryCustomer !== null) {
                $fullName = $beneficiaryCustomer->getFirstname() . ' ' . $beneficiaryCustomer->getLastname();
                $allInfo['beneficiary_customers'][$giftCard->getBeneficiaryCustomerId()] = $fullName;
            }

            $sponsorCustomer = $giftCard->getCustomerRelatedBySponsorCustomerId();
            if ($sponsorCustomer !== null) {
                $fullName = $sponsorCustomer->getFirstname() . ' ' . $sponsorCustomer->getLastname();
                $allInfo['sponsor_customers'][$giftCard->getSponsorCustomerId()] = $fullName;
            }

            $order = $giftCard->getOrder();
            if ($order !== null)
                $allInfo['orders'][$giftCard->getOrderId()] = $order->getRef();
        }

        $languages = LangQuery::create()
            ->filterByActive(1)
            ->find();

        foreach ($languages as $language) {
            $lang = array(
                'locale' => $language->getLocale(),
                'code' => $language->getCode(),
                'title' => $language->getTitle()
            );
            $allInfo['languages'][] = $lang;
        }

        $event->add(
            $this->render("gift-card-config.html",
                [
                    'all_info' => $allInfo,
                    'columnsDefinitionTransaction' => $this->defineColumnsDefinition()
                ]
            )
        );
        $event->add($this->addCSS('assets/css/style.css'));
    }

    public function onProductEditJs(HookRenderEvent $event): void
    {
        $event->add(
            $this->render(
                "datatable.js.html"
            )
        );
    }

    protected function defineColumnsDefinition(): array
    {
        return HookConfigurationManager::getdefineColumnsDefinition();
    }

    public static function getdefineColumnsDefinition(): array
    {
        $i = -1;

        return [
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_ID,
                'title' => Translator::getInstance()->trans('ID', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'orderRef',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('order', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'code',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_CODE,
                'title' => Translator::getInstance()->trans('code', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'sponsor_customer_id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_SPONSOR_CUSTOMER_ID,
                'title' => Translator::getInstance()->trans('sponsor_customer', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'beneficiary_customer_id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_BENEFICIARY_CUSTOMER_ID,
                'title' => Translator::getInstance()->trans('beneficiary_customer', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'amount',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_AMOUNT,
                'title' => Translator::getInstance()->trans('amount', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'spend_amount',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_SPEND_AMOUNT,
                'title' => Translator::getInstance()->trans('spend_amount', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'expiration_date',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_EXPIRATION_DATE,
                'title' => Translator::getInstance()->trans('expiration_date', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'created_at',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_CREATED_AT,
                'title' => Translator::getInstance()->trans('created_at', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,

            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_STATUS,
                'title' => Translator::getInstance()->trans('status', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,

            ],
            [
                'name' => 'pdf',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('pdf', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('action', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ]
        ];
    }
}
