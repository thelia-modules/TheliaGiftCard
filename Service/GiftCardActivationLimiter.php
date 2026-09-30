<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Caps the codes a customer may type in the "activate a gift card" field. Without a cap, a code
 * is found by trying them one after the other.
 *
 * Both limits are needed: the per-customer one stops a single account, the per-client one stops
 * a caller walking through several accounts.
 */
final readonly class GiftCardActivationLimiter
{
    public const PER_CUSTOMER_LIMITER = 'theliagiftcard.limiter.activation_per_customer';

    public const PER_CLIENT_LIMITER = 'theliagiftcard.limiter.activation_per_client';

    public function __construct(
        #[Autowire(service: self::PER_CUSTOMER_LIMITER)]
        private RateLimiterFactoryInterface $perCustomerLimiter,
        #[Autowire(service: self::PER_CLIENT_LIMITER)]
        private RateLimiterFactoryInterface $perClientLimiter,
    ) {
    }

    public function allows(int $customerId, ?string $clientIp): bool
    {
        $customerAccepted = $this->perCustomerLimiter->create('customer:'.$customerId)->consume()->isAccepted();

        if (null === $clientIp || '' === $clientIp) {
            return $customerAccepted;
        }

        $clientAccepted = $this->perClientLimiter->create('client:'.$clientIp)->consume()->isAccepted();

        return $customerAccepted && $clientAccepted;
    }
}
