<?php

declare(strict_types=1);

use Shopper\Api\Exceptions\PaymentNotCapturedException;
use Shopper\Core\Events\Payments\PaymentOrphaned;
use Shopper\Core\Models\Order;
use Shopper\Payment\Models\PaymentTransaction;

beforeEach(function (): void {
    seedCheckoutCart($this);
    config(['shopper.api.complete_requires_capture' => true]);
});

function notCapturedStatus(Closure $complete): ?string
{
    try {
        $complete();
    } catch (PaymentNotCapturedException $exception) {
        return $exception->status ?? 'none';
    }

    return null;
}

describe('CompleteRequiresCaptureTest', function (): void {
    it('refuses a payment the provider has not captured, without an order or a reservation', function (string $status): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = $status;

        expect(notCapturedStatus(fn () => completeCart($this->cart)))->toBe($status)
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0)
            ->and(PaymentTransaction::query()->count())->toBe(0)
            ->and($this->product->getStock())->toBe(100)
            ->and($this->driver->refunds)->toBe([])
            ->and($this->cart->refresh()->payment_session)->not->toBeNull();
    })->with(['requires_payment_method', 'requires_action', 'processing', 'canceled']);

    it('refuses a captured payment of another amount, without an order or a reservation', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';
        $this->driver->retrievedAmount = $this->driver->lastAmount - 1;

        expect(notCapturedStatus(fn () => completeCart($this->cart)))->toBe('captured')
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0)
            ->and($this->product->getStock())->toBe(100);
    });

    it('refuses a captured payment in another currency', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';
        $this->driver->retrievedCurrency = 'eur';

        expect(notCapturedStatus(fn () => completeCart($this->cart)))->toBe('captured')
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0);
    });

    it('places the order over a payment captured for the cart total', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';

        $order = completeCart($this->cart);

        expect(Order::query()->count())->toBe(1)
            ->and(reservations())->toBe(1)
            ->and($this->cart->refresh()->order_id)->toBe($order->id);
    });

    it('keeps the upstream completion when the switch is off', function (): void {
        config(['shopper.api.complete_requires_capture' => false]);
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'processing';

        expect(notCapturedStatus(fn () => completeCart($this->cart)))->toBeNull()
            ->and(Order::query()->count())->toBe(1);
    });

    it('defers an orphan whose payment is still processing', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'processing';
        $cart = $this->cart->refresh();
        $orphan = new PaymentOrphaned('fake', $cart->payment_session['reference'], $this->driver->lastAmount, $cart->public_id);

        event($orphan);

        expect($orphan->deferred)->toBeTrue()
            ->and($orphan->resolved)->toBeFalse()
            ->and(Order::query()->count())->toBe(0);
    });

    it('leaves an orphan of a payment that will not be captured unresolved', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'requires_payment_method';
        $cart = $this->cart->refresh();
        $orphan = new PaymentOrphaned('fake', $cart->payment_session['reference'], $this->driver->lastAmount, $cart->public_id);

        event($orphan);

        expect($orphan->deferred)->toBeFalse()
            ->and($orphan->resolved)->toBeFalse()
            ->and(Order::query()->count())->toBe(0);
    });
});
