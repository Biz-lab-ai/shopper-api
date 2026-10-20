<?php

declare(strict_types=1);

namespace Shopper\Api\Concerns;

use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Http\Exceptions\ApiValidationException;

/**
 * The contact email is required BEFORE money moves, not only at completion:
 * a payment session opened for a cart without a contact can be confirmed
 * at the provider and then refused by the completion, which leaves a paid
 * cart without an order. Both checkout steps share this one rule.
 */
trait EnsuresCartEmail
{
    /**
     * Freeze the contact email on the cart so the order can be confirmed and
     * looked up. A customer cart falls back to the customer's own email; a
     * guest cart must carry one, set at creation or with the checkout address.
     */
    private function ensureEmail(CartManager $cartManager, Cart $cart): void
    {
        if ($cart->email !== null) {
            return;
        }

        $email = $cart->customer?->getAttribute('email');

        if (! is_string($email)) {
            throw ApiValidationException::withCode(ErrorCode::EmailRequired, [
                'email' => __('shopper-api::messages.cart.email_required'),
            ]);
        }

        $cartManager->setEmail($cart, $email);
    }
}
