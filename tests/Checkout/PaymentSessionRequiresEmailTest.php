<?php

declare(strict_types=1);

use Shopper\Http\Enum\ErrorCode;

/**
 * The contact email is required before a payment session opens, not only at
 * completion: a session paid at the provider for a cart without a contact
 * would be refused by the completion and leave a payment without an order.
 */
beforeEach(function (): void {
    seedCheckoutCart($this);
});

describe('PaymentSessionRequiresEmailTest', function (): void {
    it('refuses a guest cart without an email before the provider is called', function (): void {
        $this->cart->forceFill(['email' => null])->save();

        expect(checkoutCode(fn () => openPaymentSession($this->cart)))->toBe(ErrorCode::EmailRequired)
            ->and($this->driver->initiations)->toBe(0)
            ->and($this->cart->refresh()->payment_session)->toBeNull();
    });

    it('opens the session once the cart carries an email', function (): void {
        $this->cart->forceFill(['email' => null])->save();
        checkoutCode(fn () => openPaymentSession($this->cart));

        $this->cart->forceFill(['email' => 'john@example.com'])->save();
        openPaymentSession($this->cart);

        expect($this->driver->initiations)->toBe(1)
            ->and($this->cart->refresh()->payment_session)->not->toBeNull();
    });

    it('still completes a cart whose session was opened with an email', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';

        expect(completeCart($this->cart)->email)->toBe('john@example.com');
    });
});
