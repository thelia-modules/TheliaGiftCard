<?php

namespace TheliaGiftCard\Api\Resource;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Serializer\Annotation\Groups;
use Thelia\Api\Bridge\Propel\Filter\SearchFilter;
use Thelia\Api\Bridge\Propel\State\PropelCollectionProvider;
use Thelia\Api\Resource\Order;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\PropelResourceTrait;
use Thelia\Api\Resource\ResourceAddonInterface;
use Thelia\Api\Resource\ResourceAddonTrait;
use TheliaGiftCard\Model\Map\GiftCardOrderTableMap;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/admin/gift-card-orders',
            paginationEnabled: true,
            provider: PropelCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [self::GROUP_ADMIN_READ]]
)]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/front/gift-card-orders',
            paginationEnabled: true,
            provider: PropelCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => [self::GROUP_FRONT_READ]]
)]
#[ApiFilter(
    filterClass: SearchFilter::class,
    properties: [
        'orderId',
    ]
)]
class GiftCardOrderList implements PropelResourceInterface, ResourceAddonInterface
{
    use PropelResourceTrait;
    use ResourceAddonTrait;

    public const GROUP_ADMIN_READ = 'admin:gift_card_order:read';
    public const GROUP_FRONT_READ = 'front:gift_card_order:read';

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $id = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $giftCardId = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?float $spendAmount = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?float $initialPostage = null;

    #[Groups([self::GROUP_ADMIN_READ, self::GROUP_FRONT_READ])]
    public ?int $status = null;

    #[Groups([Order::GROUP_ADMIN_READ, Order::GROUP_ADMIN_WRITE, Order::GROUP_FRONT_READ, Order::GROUP_FRONT_READ_SINGLE])]
    public ?int $orderId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getGiftCardId(): ?int
    {
        return $this->giftCardId;
    }

    public function setGiftCardId(int $giftCardId): self
    {
        $this->giftCardId = $giftCardId;
        return $this;
    }

    public function getSpendAmount(): ?float
    {
        return $this->spendAmount;
    }

    public function setSpendAmount(?float $spendAmount): self
    {
        $this->spendAmount = $spendAmount;
        return $this;
    }

    public function getInitialPostage(): ?float
    {
        return $this->initialPostage;
    }

    public function setInitialPostage(?float $initialPostage): self
    {
        $this->initialPostage = $initialPostage;
        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function setOrderId(?int $orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }

    #[Ignore]
    public static function getPropelRelatedTableMap(): ?TableMap
    {
        return new GiftCardOrderTableMap();
    }

    #[Ignore] public static function getResourceParent(): string
    {
        return Order::class;
    }
}
