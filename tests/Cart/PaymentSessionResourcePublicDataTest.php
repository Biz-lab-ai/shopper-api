<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Shopper\Api\Http\Resources\PaymentSessionResource;
use Shopper\Api\Support\PaymentSession;
use Shopper\Cart\Models\Cart;
use Shopper\Payment\DataTransferObjects\PaymentResult;

/**
 * The session answer carries what a client needs to confirm the intent on
 * the right account: the publishable key AND the connected account. Provider
 * internals the driver stuffed into `data` still stay server-side.
 */
describe('PaymentSessionResourcePublicDataTest', function (): void {
    it('exposes the connected account next to the publishable key and keeps the rest private', function (): void {
        $result = new PaymentResult(
            success: true,
            status: 'pending',
            reference: 'pi_1',
            clientSecret: 'pi_1_secret',
            amount: 5000,
            data: [
                'publishable_key' => 'pk_test',
                'stripe_account' => 'acct_owner',
                'stripe_status' => 'requires_payment_method',
                'idempotency_key' => 'cart-1',
                'charges' => 'ch_1',
                'payment_method' => 'pm_1',
            ],
        );
        $session = new PaymentSession(new Cart(['currency_code' => 'USD']), 'stripe', $result);

        $attributes = PaymentSessionResource::make($session)->toAttributes(Request::create('/'));

        expect($attributes['client_secret'])->toBe('pi_1_secret')
            ->and($attributes['data'])->toBe([
                'publishable_key' => 'pk_test',
                'stripe_account' => 'acct_owner',
                'stripe_status' => 'requires_payment_method',
                'idempotency_key' => 'cart-1',
            ]);
    });

    it('answers a null account for a platform payment instead of dropping the key', function (): void {
        $result = new PaymentResult(success: true, status: 'pending', reference: 'pi_1', data: ['publishable_key' => 'pk_test', 'stripe_account' => null]);
        $session = new PaymentSession(new Cart(['currency_code' => 'USD']), 'stripe', $result);

        expect(PaymentSessionResource::make($session)->toAttributes(Request::create('/'))['data'])
            ->toBe(['publishable_key' => 'pk_test', 'stripe_account' => null]);
    });
});
