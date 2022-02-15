<?php

namespace TheliaGiftCard\Model\Api;

use OpenApi\Annotations as OA;
use OpenApi\Constraint as Constraint;

use OpenApi\Model\Api\BaseApiModel;
use TheliaGiftCard\Model\Api\GiftCard as TheliaGifCard;

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
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     * @Constraint\NotBlank(groups={"read", "update"})
     */
    protected $id;

    /**
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     * @Constraint\NotBlank(groups={"create", "update"})
     */
    protected $sponsorCustomerId;

    /**
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected $beneficiaryCustomerId;

    /**
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected $orderId;

    /**
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected $productId;

    /**
     * @var string
     * @OA\Property(
     *    type="string"
     * )
     */
    protected $productName;

    /**
     * @var string
     * @OA\Property(
     *    type="string"
     * )
     * @Constraint\NotBlank(groups={"create", "update"})
     */
    protected $code;

    /**
     * @var float
     * @OA\Property(
     *    type="float"
     * )
     */
    protected $amount;

    /**
     * @var float
     * @OA\Property(
     *    type="float"
     * )
     */
    protected $spend_amount;

    /**
     * @var integer
     * @OA\Property(
     *    type="integer"
     * )
     */
    protected $status;

    /**
     * @var \DateTime
     * @OA\Property(
     *    type="\DateTime"
     * )
     */
    protected $expirationDate;

    /**
     * @var \DateTime
     * @OA\Property(
     *    type="\DateTime"
     * )
     */
    protected $createdAt;

    /**
     * @var boolean
     * @OA\Property(
     *    type="boolean"
     * )
     */
    protected $expired;

    /**
     * @var float
     * @OA\Property(
     *    type="float"
     * )
     */
    protected $cartspendAmount;

    /**
     * @param \TheliaGiftCard\Model\GiftCard $giftCard
     * @param string $locale
     * @return $this|GiftCard
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function createFromTheliaModel($giftCard, $locale = 'en_US')
    {
        parent::createFromTheliaModel($giftCard, $locale);

        $this->setProductName($giftCard->getVirtualColumn('PRODUCT_TITLE'));
        $this->setCartspendAmount(($currentAmount = $giftCard->getVirtualColumn("CART_SPEND_AMOUNT")) ? $currentAmount : 0);

        $delta = (new \DateTime())
            ->diff($giftCard->getExpirationDate())
            ->format('%r');

        $this->setExpired(($giftCard->getAmount() <= $giftCard->getSpendAmount() || null != $delta) ? 1 : 0);

        return $this;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId(int $id)
    {
        $this->id = $id;
    }

    /**
     * @return int
     */
    public function getSponsorCustomerId()
    {
        return $this->sponsorCustomerId;
    }

    /**
     * @param int $sponsorCustomerId
     */
    public function setSponsorCustomerId(int $sponsorCustomerId = null)
    {
        $this->sponsorCustomerId = $sponsorCustomerId;
    }

    /**
     * @return int
     */
    public function getBeneficiaryCustomerId()
    {
        return $this->beneficiaryCustomerId;
    }

    /**
     * @param int $beneficiaryCustomerId
     */
    public function setBeneficiaryCustomerId(int $beneficiaryCustomerId)
    {
        $this->beneficiaryCustomerId = $beneficiaryCustomerId;
    }

    /**
     * @return int
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * @param int $orderId
     */
    public function setOrderId(int $orderId = null)
    {
        $this->orderId = $orderId;
    }

    /**
     * @return int
     */
    public function getProductId()
    {
        return $this->productId;
    }

    /**
     * @param int $productId
     */
    public function setProductId(int $productId = null)
    {
        $this->productId = $productId;
    }

    /**
     * @return string
     */
    public function getProductName()
    {
        return $this->productName;
    }

    /**
     * @param string $productName
     */
    public function setProductName(string $productName = null)
    {
        $this->productName = $productName;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @param string $code
     */
    public function setCode(string $code)
    {
        $this->code = $code;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * @param float $amount
     */
    public function setAmount(float $amount)
    {
        $this->amount = $amount;
    }

    /**
     * @return float
     */
    public function getSpendAmount()
    {
        return $this->spend_amount;
    }

    /**
     * @param float $spend_amount
     */
    public function setSpendAmount(float $spend_amount = 0)
    {
        $this->spend_amount = $spend_amount;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function setStatus(int $status)
    {
        $this->status = $status;
    }

    /**
     * @return \DateTime
     */
    public function getExpirationDate()
    {
        return $this->expirationDate;
    }

    /**
     * @param \DateTime $expirationDate
     */
    public function setExpirationDate(\DateTime $expirationDate = null)
    {
        $this->expirationDate = $expirationDate;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @param \DateTime $createdAt
     */
    public function setCreatedAt(\DateTime $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * @return float
     */
    public function getCartspendAmount()
    {
        return $this->cartspendAmount;
    }

    /**
     * @param float $cartspendAmount
     */
    public function setCartspendAmount(float $cartspendAmount = 0)
    {
        $this->cartspendAmount = $cartspendAmount;
    }

    /**
     * @return bool
     */
    public function isExpired()
    {
        return $this->expired;
    }

    /**
     * @param bool $expired
     */
    public function setExpired(bool $expired = true)
    {
        $this->expired = $expired;
    }
}