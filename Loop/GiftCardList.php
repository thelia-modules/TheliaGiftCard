<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard\Loop;

use DateTime;
use PDO;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Model\Map\ProductI18nTableMap;
use Thelia\Type\TypeCollection;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use Thelia\Type;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

class GiftCardList extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('card_id'),
            new Argument(
                'customer',
                new TypeCollection(
                    new Type\IntType(),
                    new Type\EnumType(array('current', '*'))
                ),
                null
            ),
            new Argument(
                'desactivate',
                new TypeCollection(
                    new Type\BooleanType()
                ),
                true
            ),
            new Argument(
                'expired',
                new TypeCollection(
                    new Type\BooleanType()
                ),
                false
            )
            ,
            new Argument(
                'current_cart',
                new TypeCollection(
                    new Type\BooleanType()
                ),
                false
            )
        );
    }

    public function buildModelCriteria()
    {
        $cardId = $this->getCardId();
        $customer = $this->getCustomer();
        $expired = $this->getExpired();
        $desactivate = $this->getDesactivate();
        $currentCart = $this->getCurrentCart();

        $locale = $this->getCurrentRequest()->getSession()->getLang()->getLocale();

        $search = GiftCardQuery::create()
            ->useProductQuery('')
            ->useProductI18nQuery('', Criteria::LEFT_JOIN)
            ->filterByLocale($locale)
            ->_or()
            ->filterByLocale(null, Criteria::ISNULL)
            ->endUse()
            ->endUse()
            ->withColumn(ProductI18nTableMap::TABLE_NAME . '.' . 'title', 'product_title');


        if ($customer === 'current') {
            $current = $this->securityContext->getCustomerUser();
            if ($current === null) {
                return null;
            } else {
                $search->filterByBeneficiaryCustomerId($current->getId(), Criteria::EQUAL);
            }
        } elseif ($customer !== null) {
            $search->filterByBeneficiaryCustomerId($customer, Criteria::EQUAL);
        }

        if (!$expired) {
            $search->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT);
        }

        if (!$desactivate) {
            $search->filterByStatus(1);
        }

        if ($cardId) {
            $search->filterById($cardId);
        }

        if (false === $this->getBackendContext() && null == $customer) {
            return null;
        }

        $search->groupby(GiftCardTableMap::COL_ID);

        if ($currentCart) {
            $cart = $this->getCurrentRequest()->getSession()->getSessionCart();

            if ($cart === null) {
                return $search;
            }

            $giftCardCartJoin = new Join();
            $giftCardCartJoin->addExplicitCondition(
                GiftCardTableMap::TABLE_NAME,
                'ID',
                '',
                GiftCardCartTableMap::TABLE_NAME,
                'GIFT_CARD_ID'
            );

            $giftCardCartJoin->setJoinType(Criteria::LEFT_JOIN);

            $search
                ->addJoinObject($giftCardCartJoin, 'cart_join')
                ->addJoinCondition('cart_join', GiftCardCartTableMap::COL_CART_ID . ' = ?', $cart->getId(), Criteria::EQUAL, PDO::PARAM_INT)
                ->withColumn("SUM(" . GiftCardCartTableMap::COL_SPEND_AMOUNT . ")", 'CART_SPEND_AMOUNT');
        }

        return $search;
    }

    public function parseResults(LoopResult $loopResult)
    {
        $dateNow = new DateTime();

        /** @var GiftCard $giftCard */
        foreach ($loopResult->getResultDataCollection() as $giftCard) {
            $loopResultRow = (new LoopResultRow($giftCard))
                ->set('ID', $giftCard->getId())
                ->set('SPONSOR_CUSTOMER_ID', $giftCard->getSponsorCustomerId())
                ->set('BENEFICIARY_CUSTOMER_ID', $giftCard->getBeneficiaryCustomerId())
                ->set('ORDER_ID', $giftCard->getOrderId())
                ->set('PRODUCT_ID', $giftCard->getProductId())
                ->set('PRODUCT_NAME', $giftCard->getVirtualColumn("product_title"))
                ->set('CODE', $giftCard->getCode())
                ->set('AMOUNT', $giftCard->getAmount())
                ->set('SPEND_AMOUNT', $giftCard->getSpendAmount())
                ->set('STATUS', $giftCard->getStatus())
                ->set('EXPIRATION_DATE', $giftCard->getExpirationDate('d/m/Y'))
                ->set('CREATE_AT', $giftCard->getCreatedAt('d/m/Y'));

            $delta = null;
            
            //Si date d'expiration inférieure à la date du jour
            if ($giftCard->getExpirationDate()) {
                $delta = $dateNow->diff($giftCard->getExpirationDate())->format('%r');
            }

            $loopResultRow->set('EXPIRED', 0);

            $value = 0;
            $loopResultRow->set('CART_SPEND_AMOUNT', $value);

            if ($this->getCurrentCart() && $value = $giftCard->getVirtualColumn("CART_SPEND_AMOUNT")) {
                $loopResultRow->set('CART_SPEND_AMOUNT', $value + $giftCard->getSpendAmount());
            } else {
                $loopResultRow->set('CART_SPEND_AMOUNT', $giftCard->getSpendAmount());
            }

            if ($giftCard->getAmount() <= $giftCard->getSpendAmount() || null != $delta) {
                $loopResultRow->set('EXPIRED', 1);

                if (!$this->getExpired()) {
                    continue;
                }

            }

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}