<?php

namespace TheliaGiftCard\Model\Api;

use DateTime;
use OpenApi\Annotations as OA;
use OpenApi\Constraint as Constraint;

use OpenApi\Model\Api\BaseApiModel;
use Propel\Runtime\Exception\PropelException;

/**
 * @OA\Schema(
 *     schema="GiftCard",
 *     title="GiftCard",
 *     description="GiftCard model"
 * )
 *
 */
class GiftCard extends BaseApiModel
{
    /**
     * @OA\Property(
     *    type="integer"
     * )
     * @Constraint\NotBlank(groups={"read", "update"})
     */
    protected int $id;

    /**
     * @OA\Property(
     *    type="integer"
     * )
     * @Constraint\NotBlank(groups={"create", "update"})
     */
    protected int $sponsorCustomerId;

    /**
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected int $beneficiaryCustomerId;

    /**
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected int $orderId;

    /**
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected int $productId;

    /**
     * @OA\Property(
     *    type="string"
     * )
     */
    protected string $productName;

    /**
     * @OA\Property(
     *    type="string"
     * )
     * @Constraint\NotBlank(groups={"create", "update"})
     */
    protected string $code;

    /**
     * @OA\Property(
     *    type="float"
     * )
     */
    protected float $amount;

    /**
     * @OA\Property(
     *    type="float"
     * )
     */
    protected float $spend_amount;

    /**
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected int $status;

    /**
     * @OA\Property(
     *    type="\DateTime"
     * )
     */
    protected ?DateTime $expirationDate;

    /**
     * @OA\Property(
     *    type="\DateTime"
     * )
     */
    protected DateTime $createdAt;

    /**
     * @OA\Property(
     *    type="boolean"
     * )
     */
    protected bool $expired;

    /**
     * @OA\Property(
     *    type="float"
     * )
     */
    protected float $cartspendAmount;

    /**
     * @throws PropelException
     */
    public function createFromTheliaModel($theliaModel, $locale = 'en_US'): void
    {
        parent::createFromTheliaModel($theliaModel, $locale);

        $this->setProductName($theliaModel->getVirtualColumn('PRODUCT_TITLE'));
        $this->setCartspendAmount(($currentAmount = $theliaModel->getVirtualColumn("CART_SPEND_AMOUNT")) ? $currentAmount : 0);

        if (!$theliaModel->getExpirationDate()) {
            $this->setExpired(0);
            return;
        }

        $delta = (new DateTime())
            ->diff($theliaModel->getExpirationDate())
            ->format('%r');

        $this->setExpired(($theliaModel->getAmount() <= $theliaModel->getSpendAmount() || null != $delta) ? 1 : 0);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getSponsorCustomerId(): int
    {
        return $this->sponsorCustomerId;
    }

    public function setSponsorCustomerId(int $sponsorCustomerId = null): void
    {
        $this->sponsorCustomerId = $sponsorCustomerId;
    }

    public function getBeneficiaryCustomerId(): int
    {
        return $this->beneficiaryCustomerId;
    }

    public function setBeneficiaryCustomerId(int $beneficiaryCustomerId): void
    {
        $this->beneficiaryCustomerId = $beneficiaryCustomerId;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId = null): void
    {
        $this->orderId = $orderId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function setProductId(int $productId = null): void
    {
        $this->productId = $productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName = null): void
    {
        $this->productName = $productName;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function getSpendAmount(): float
    {
        return $this->spend_amount;
    }

    public function setSpendAmount(float $spend_amount = 0): void
    {
        $this->spend_amount = $spend_amount;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getExpirationDate(): DateTime
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(?DateTime $expirationDate = null): void
    {
        $this->expirationDate = $expirationDate;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCartspendAmount(): float
    {
        return $this->cartspendAmount;
    }

    public function setCartspendAmount(float $cartspendAmount = 0): void
    {
        $this->cartspendAmount = $cartspendAmount;
    }

    public function isExpired(): bool
    {
        return $this->expired;
    }

    public function setExpired(bool $expired = true): void
    {
        $this->expired = $expired;
    }
}