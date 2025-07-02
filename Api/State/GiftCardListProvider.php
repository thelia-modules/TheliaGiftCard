<?php

namespace TheliaGiftCard\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Map\ProductI18nTableMap;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

class GiftCardListProvider implements ProviderInterface
{
    public function __construct(
        private RequestStack    $requestStack,
        private SecurityContext $securityContext
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|object|null
    {
        $filters = $context['filters'] ?? [];

        $cardId = $filters['cardId'] ?? null;
        $customer = $filters['customer'] ?? null;
        $expired = filter_var($filters['expired'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $desactivate = filter_var($filters['desactivate'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $currentCart = filter_var($filters['currentCart'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        $locale = $session?->getLang()?->getLocale() ?? 'fr_FR';

        $search = GiftCardQuery::create()
            ->useProductQuery()
            ->useProductI18nQuery(null, Criteria::LEFT_JOIN)
            ->filterByLocale($locale)
            ->_or()
            ->filterByLocale(null, Criteria::ISNULL)
            ->endUse()
            ->endUse()
            ->withColumn(ProductI18nTableMap::TABLE_NAME . '.title', 'product_title');

        if ($customer === 'current') {
            $current = $this->securityContext->getCustomerUser();
            if ($current === null) {
                return [];
            }
            $search->filterByBeneficiaryCustomerId($current->getId(), Criteria::EQUAL);
        } elseif (!empty($customer)) {
            $search->filterByBeneficiaryCustomerId((int)$customer, Criteria::EQUAL);
        }

        if (!$expired) {
            $search->where(GiftCardTableMap::COL_SPEND_AMOUNT . ' < ' . GiftCardTableMap::COL_AMOUNT);
        }

        if (!$desactivate) {
            $search->filterByStatus(1);
        }

        if ($cardId !== null) {
            $search->filterById((int)$cardId);
        }

        if ($operation->getName() !== 'admin' && $customer === null) {
            return [];
        }

        $search->groupBy(GiftCardTableMap::COL_ID);

        if ($currentCart) {
            $cart = $session?->getSessionCart();
            if ($cart !== null) {
                $join = new Join();
                $join->addExplicitCondition(
                    GiftCardTableMap::TABLE_NAME, 'ID', '',
                    GiftCardCartTableMap::TABLE_NAME, 'GIFT_CARD_ID'
                );
                $join->setJoinType(Criteria::LEFT_JOIN);

                $search
                    ->addJoinObject($join, 'cart_join')
                    ->addJoinCondition('cart_join', GiftCardCartTableMap::COL_CART_ID . ' = ?', $cart->getId(), Criteria::EQUAL, \PDO::PARAM_INT)
                    ->withColumn("SUM(" . GiftCardCartTableMap::COL_SPEND_AMOUNT . ")", 'CART_SPEND_AMOUNT');
            }
        }

        return iterator_to_array($search->find());
    }
}
