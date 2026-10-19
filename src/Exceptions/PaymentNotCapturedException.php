<?php

declare(strict_types=1);

namespace Shopper\Api\Exceptions;

use RuntimeException;

/**
 * The provider does not report the payment of the cart captured for the
 * cart total and currency: the cart is not completed. The status is the
 * one the provider reported.
 */
final class PaymentNotCapturedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $status,
    ) {
        parent::__construct("The payment is not captured (provider status: {$status}).");
    }
}
