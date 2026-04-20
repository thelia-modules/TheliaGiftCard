<?php

namespace TheliaGiftCard\Api\Resource;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Ignore;
use Thelia\Api\Bridge\Propel\Filter\BooleanFilter;
use Thelia\Api\Bridge\Propel\Filter\SearchFilter;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\PropelResourceTrait;
use TheliaGiftCard\Api\State\GiftCardListProvider;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/admin/gift-cards',
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'expired',
                        'in' => 'query',
                        'description' => 'Filter by expired gift cards',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'desactivate',
                        'in' => 'query',
                        'description' => 'Filter by deactivated gift cards',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'currentCart',
                        'in' => 'query',
                        'description' => 'Filter by gift cards related to the current cart',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'beneficiaryCustomerId',
                        'in' => 'query',
                        'description' => 'Filter by beneficiary customer ID',
                        'required' => false,
                        'schema' => ['type' => 'integer'],
                    ],
                ],
            ],
            paginationEnabled: true,
            provider: GiftCardListProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/front/gift-cards',
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'expired',
                        'in' => 'query',
                        'description' => 'Filter by expired gift cards',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'desactivate',
                        'in' => 'query',
                        'description' => 'Filter by deactivated gift cards',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'currentCart',
                        'in' => 'query',
                        'description' => 'Filter by gift cards related to the current cart',
                        'required' => false,
                        'schema' => ['type' => 'boolean'],
                    ],
                    [
                        'name' => 'beneficiaryCustomerId',
                        'in' => 'query',
                        'description' => 'Filter by beneficiary customer ID',
                        'required' => false,
                        'schema' => ['type' => 'integer'],
                    ],
                ],
            ],
            paginationEnabled: true,
            provider: GiftCardListProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ]]
)]
#[ApiFilter(BooleanFilter::class, properties: [
    'expired', 'desactivate', 'currentCart'
])]
#[ApiFilter(SearchFilter::class, properties: [
    'beneficiaryCustomerId',
])]
class GiftCardList implements PropelResourceInterface
{
    use PropelResourceTrait;

    public const GROUP_ADMIN_READ = 'admin:gift_card:read';
    public const GROUP_FRONT_READ = 'front:gift_card:read';

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $id = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?string $code = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?float $amount = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?float $spendAmount = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $status = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $beneficiaryCustomerId = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $orderId = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?\DateTime $createdAt = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?\DateTime $updatedAt = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?string $productName = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?float $cartSpendAmount = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?bool $expired = null;

    #[Ignore]
    public static function getPropelRelatedTableMap(): ?\Propel\Runtime\Map\TableMap
    {
        return new GiftCardTableMap();
    }
}
