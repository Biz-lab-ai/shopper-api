<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Shopper\Api\Http\Resources\PaymentSessionResource;
use Shopper\Api\Support\PaymentSession;
use Shopper\Cart\Models\Cart;
use Shopper\Payment\DataTransferObjects\PaymentResult;

/**
 * Only the keys a storefront needs to confirm a session cross the wire;
 * whatever else a driver put into `data` stays server-side.
 */
describe('PaymentSessionResourcePublicDataTest', function (): void {
    it('exposes the publishable key and idempotency key and keeps the rest private', function (): void {
        $result = new PaymentResult(
            success: true,
            status: 'pending',
            reference: 'ref_1',
            clientSecret: 'ref_1_secret',
            amount: 5000,
            data: [
                'publishable_key' => 'pk_test',
                'idempotency_key' => 'cart-1',
                'provider_account' => 'acct_owner',
                'charges' => 'ch_1',
            ],
        );
        $session = new PaymentSession(new Cart(['currency_code' => 'USD']), 'manual', $result);

        $attributes = PaymentSessionResource::make($session)->toAttributes(Request::create('/'));

        expect($attributes['client_secret'])->toBe('ref_1_secret')
            ->and($attributes['data'])->toBe([
                'publishable_key' => 'pk_test',
                'idempotency_key' => 'cart-1',
            ]);
    });
});
