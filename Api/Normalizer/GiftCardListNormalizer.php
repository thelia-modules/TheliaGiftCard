<?php

namespace TheliaGiftCard\Api\Normalizer;

use DateTime;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use TheliaGiftCard\Model\GiftCard;

class GiftCardListNormalizer implements NormalizerInterface
{
    public function __construct()
    {
    }

    public function normalize($object, string $format = null, array $context = []): array
    {
        /** @var GiftCard $object */
        $output = [
            'id' => $object->getId(),
            'code' => $object->getCode(),
            'amount' => $object->getAmount(),
            'spendAmount' => $object->getSpendAmount(),
            'status' => $object->getStatus(),
            'beneficiaryCustomerId' => $object->getBeneficiaryCustomerId(),
            'orderId' => $object->getOrderId(),
            'createdAt' => $object->getCreatedAt(),
            'updatedAt' => $object->getUpdatedAt(),
            'productName' => $object->getVirtualColumn('product_title'),
            'cartSpendAmount' => (float)$object->getVirtualColumn('CART_SPEND_AMOUNT') + $object->getSpendAmount(),
            'expired' => false,
        ];

        if ($object->getExpirationDate()) {
            $now = new DateTime();
            if ($object->getExpirationDate() < $now || $object->getAmount() <= $object->getSpendAmount()) {
                $output['expired'] = true;
            }
        }

        return $output;
    }

    public function supportsNormalization($data, string $format = null, array $context = []): bool
    {
        return $data instanceof GiftCard;
    }
}
