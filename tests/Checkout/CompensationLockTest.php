<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Shopper\Api\Tests\Stubs\ContendedArrayStore;
use Shopper\Api\Tests\Stubs\RecordingCompensationStore;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Contracts\PaymentSessionGateway;
use Shopper\Core\Events\Payments\PaymentOrphaned;
use Shopper\Core\Exceptions\PaymentProviderUnavailableException;
use Shopper\Core\Models\Order;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Enum\WebhookAction;
use Shopper\Payment\Models\PaymentWebhookEvent;
use Shopper\Payment\PaymentReferenceLock;

beforeEach(function (): void {
    seedCheckoutCart($this);

    $store = $this->cacheStore = new ContendedArrayStore;
    Cache::extend('contended', fn () => Cache::repository($store));
    config([
        'cache.stores.contended' => ['driver' => 'contended'],
        'cache.default' => 'contended',
        'shopper.payment.compensation_store' => RecordingCompensationStore::class,
        'shopper.payment.reconciliation.orphans' => 'refund',
    ]);
    RecordingCompensationStore::$decisions = [];

    openPaymentSession($this->cart);
    $this->driver->retrievedStatus = 'captured';
    $this->session = Cart::query()->findOrFail($this->cart->id)->payment_session;
    $this->reference = $this->session['reference'];
});

/**
 * The webhook of a captured payment no completion has claimed yet, old
 * enough for the reconciliation to treat it as an orphan.
 */
function journalOrphan(Cart $cart, string $reference, int $amount): void
{
    PaymentWebhookEvent::journal('fake', new WebhookResult(
        action: WebhookAction::Captured,
        reference: $reference,
        amount: $amount,
        data: ['cart_id' => $cart->public_id],
        eventId: 'evt_'.$reference,
    ));

    PaymentWebhookEvent::query()->forReference($reference)->update(['created_at' => now()->subHour()]);
}

function holdReferenceLock(string $reference): void
{
    expect(Cache::lock(PaymentReferenceLock::key($reference), 10, 'other')->get())->toBeTrue();
}

function releaseReferenceLock(string $reference): void
{
    Cache::lock(PaymentReferenceLock::key($reference))->forceRelease();
}

describe('CompensationLockTest', function (): void {
    it('never turns a payment given back for missing stock into an order', function (): void {
        $this->driver->refundStatus = 'refund_requires_action';
        $this->product->decreaseStock($this->inventory->id, 100);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentReleased)
            ->and(Cart::query()->findOrFail($this->cart->id)->payment_session)->toBeNull()
            ->and(RecordingCompensationStore::$decisions[$this->reference]['cause'])->toBe('stock_insufficient');

        $this->product->mutateStock($this->inventory->id, 100);

        $repeated = null;

        try {
            completeCart($this->cart);
        } catch (Throwable $exception) {
            $repeated = $exception;
        }

        expect($repeated)->not->toBeNull();

        $orphan = new PaymentOrphaned('fake', $this->reference, $this->session['amount'], $this->cart->public_id);
        event($orphan);

        journalOrphan($this->cart, $this->reference, $this->session['amount']);
        $this->artisan('shopper:payments:reconcile')->assertSuccessful();

        expect($orphan->resolved)->toBeFalse()
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0)
            ->and(collect($this->driver->refunds)->pluck('context.idempotency_key')->unique()->all())->toHaveCount(1);
    });

    it('records the decision before the provider is called and repeats it with the same idempotency key', function (): void {
        $gateway = app(PaymentSessionGateway::class);
        $this->driver->throwOnRefund = true;

        expect(fn () => $gateway->refund($this->session))->toThrow(PaymentProviderUnavailableException::class)
            ->and(RecordingCompensationStore::$decisions)->toHaveKey($this->reference);

        $this->driver->throwOnRefund = false;

        expect($gateway->refund($this->session))->toBeTrue()
            ->and($this->driver->refunds)->toHaveCount(2)
            ->and($this->driver->refunds[0]['context']['idempotency_key'])->toBe($this->driver->refunds[1]['context']['idempotency_key'])
            ->and(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentReleased)
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0);
    });

    it('makes the refund of an orphan wait for a completion holding the lock and give nothing back', function (): void {
        holdReferenceLock($this->reference);
        $this->cacheStore->onConflict = function (): void {
            releaseReferenceLock($this->reference);
            completeCart($this->cart);
        };

        $refunded = app(PaymentSessionGateway::class)->refund([...$this->session, 'cause' => 'orphan']);

        expect($refunded)->toBeFalse()
            ->and($this->driver->refunds)->toBe([])
            ->and(RecordingCompensationStore::$decisions)->toBe([])
            ->and(Order::query()->count())->toBe(1)
            ->and(reservations())->toBe(1);
    });

    it('makes a completion wait for a refund holding the lock and refuse with the payment released', function (): void {
        holdReferenceLock($this->reference);
        $this->cacheStore->onConflict = function (): void {
            releaseReferenceLock($this->reference);
            app(PaymentSessionGateway::class)->refund([...$this->session, 'cause' => 'orphan']);
        };

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentReleased)
            ->and($this->driver->refunds)->toHaveCount(1)
            ->and(RecordingCompensationStore::$decisions[$this->reference]['cause'])->toBe('orphan')
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0);
    });

    it('lets a nested entry of the same process through without waiting', function (): void {
        $conflicts = 0;
        $this->cacheStore->onConflict = function () use (&$conflicts): void {
            $conflicts++;
        };
        $started = microtime(true);

        $result = PaymentReferenceLock::run($this->reference, fn (): string => PaymentReferenceLock::run($this->reference, fn (): string => 'inner'));

        expect($result)->toBe('inner')
            ->and($conflicts)->toBe(0)
            ->and(microtime(true) - $started)->toBeLessThan(1.0);
    });

    it('keeps the upstream compensation without a compensation store', function (): void {
        config(['shopper.payment.compensation_store' => null]);
        $this->product->decreaseStock($this->inventory->id, 100);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentReleased)
            ->and($this->driver->refunds)->toHaveCount(1)
            ->and(Cart::query()->findOrFail($this->cart->id)->payment_session)->toBeNull()
            ->and(RecordingCompensationStore::$decisions)->toBe([])
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0);
    });
});
