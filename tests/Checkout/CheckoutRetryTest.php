<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shopper\Api\Actions\CompleteCartAction;
use Shopper\Api\Tests\Stubs\Conflict;
use Shopper\Cart\Actions\CreateOrderFromCartAction;
use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartPromotion;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Payment\Models\PaymentTransaction;

beforeEach(function (): void {
    seedCheckoutCart($this);
});

/**
 * Throw the snapshot conflict once, the first time the event fires inside
 * a transaction: the preparation at level 0 is never hit.
 */
function conflictOnceInTransaction(string $event): void
{
    $thrown = false;

    Event::listen($event, function () use (&$thrown): void {
        if (! $thrown && DB::transactionLevel() > 0) {
            $thrown = true;

            throw Conflict::snapshot();
        }
    });
}

describe('CheckoutRetryTest', function (): void {
    it('repeats a checkout conflicted after the payment transaction into one order, one reservation and one transaction', function (): void {
        openPaymentSession($this->cart);
        conflictOnceInTransaction('eloquent.created: '.PaymentTransaction::class);
        $this->runner->attempted = 0;

        $order = completeCart($this->cart);

        expect($this->runner->attempted)->toBe(2)
            ->and(Order::query()->count())->toBe(1)
            ->and(reservations())->toBe(1)
            ->and(PaymentTransaction::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(PaymentTransaction::query()->count())->toBe(1)
            ->and($this->cart->refresh()->order_id)->toBe($order->id);
    });

    it('rolls everything back on a wrong session amount without a repeat', function (): void {
        openPaymentSession($this->cart);
        $this->cart->lines()->first()->update(['quantity' => 2]);
        $this->runner->attempted = 0;

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentSessionMismatch)
            ->and($this->runner->attempted)->toBe(1)
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0)
            ->and(PaymentTransaction::query()->count())->toBe(0)
            ->and($this->product->getStock())->toBe(100);
    });

    it('gives a paid cart short of stock back exactly once, outside the rolled back attempt', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';
        $this->product->decreaseStock($this->inventory->id, 100);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentReleased)
            ->and($this->driver->refunds)->toHaveCount(1)
            ->and(Cart::query()->findOrFail($this->cart->id)->payment_session)->toBeNull()
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0)
            ->and(PaymentTransaction::query()->count())->toBe(0);
    });

    it('keeps the released session and the recalculated cart of a refused promotion', function (): void {
        $discount = Discount::factory()->create([
            'code' => 'SAVE20',
            'is_active' => true,
            'type' => DiscountType::Percentage,
            'value' => 20,
            'apply_to' => DiscountApplyTo::Order,
            'eligibility' => DiscountEligibility::Everyone,
            'min_required' => DiscountRequirement::None,
            'usage_limit' => 1,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        app(CartManager::class)->applyCoupon($this->cart->refresh(), 'SAVE20');
        openPaymentSession($this->cart);
        $discount->update(['total_use' => 1]);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PromotionLimitReached)
            ->and($this->driver->lastCancelledReference)->toBe('fake_intent_1')
            ->and(Cart::query()->findOrFail($this->cart->id)->payment_session)->toBeNull()
            ->and((int) CartPromotion::query()->where('cart_id', $this->cart->id)->sum('computed_amount'))->toBe(0)
            ->and(Order::query()->count())->toBe(0);
    });

    it('keeps the repriced cart of a refused price change', function (): void {
        $this->product->prices()->update(['amount' => 3000]);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PriceChanged)
            ->and($this->cart->lines()->first()->unit_price_amount)->toBe(3000)
            ->and(Order::query()->count())->toBe(0);
    });

    it('never calls a compensation between the attempts of a checkout that succeeds', function (): void {
        openPaymentSession($this->cart);
        $this->driver->retrievedStatus = 'captured';
        conflictOnceInTransaction('eloquent.created: '.Order::class);
        $this->runner->attempted = 0;

        completeCart($this->cart);

        expect($this->runner->attempted)->toBe(2)
            ->and($this->driver->refunds)->toBeEmpty()
            ->and($this->driver->cancellations)->toBe(0)
            ->and($this->cart->lines()->first()->unit_price_amount)->toBe(2500)
            ->and(Order::query()->count())->toBe(1);
    });

    it('refuses to complete a cart inside an open transaction before any query', function (): void {
        $cart = Cart::query()->findOrFail($this->cart->id);
        $queries = 0;

        expect(fn () => DB::transaction(function () use ($cart, &$queries): void {
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            app(CompleteCartAction::class)->execute($cart);
        }))->toThrow(LogicException::class);

        expect($queries)->toBe(0)
            ->and(Order::query()->count())->toBe(0);
    });

    it('commits the release of an uncollected session once when the shipping fee drops before a refused order', function (): void {
        openPaymentSession($this->cart);
        $this->option->update(['price' => 80]);

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentSessionRequired)
            ->and($this->driver->cancellations)->toBe(1);

        $cart = Cart::query()->findOrFail($this->cart->id);

        expect($cart->payment_session)->toBeNull()
            ->and($cart->shipping_amount)->toBe(80)
            ->and(Order::query()->count())->toBe(0);
    });

    it('never repeats the release of the preparation when an attempt conflicts', function (): void {
        openPaymentSession($this->cart);
        $this->option->update(['price' => 80]);
        conflictOnceInTransaction('eloquent.retrieved: '.Cart::class);
        $this->runner->attempted = 0;

        expect(checkoutCode(fn () => completeCart($this->cart)))->toBe(ErrorCode::PaymentSessionRequired)
            ->and($this->runner->attempted)->toBe(2)
            ->and($this->driver->cancellations)->toBe(1)
            ->and(Cart::query()->findOrFail($this->cart->id)->shipping_amount)->toBe(80);
    });

    it('lets a direct order creation with caller closures fail loudly on a conflict', function (): void {
        $thrown = 0;
        $cart = Cart::query()->findOrFail($this->cart->id);

        try {
            app(CreateOrderFromCartAction::class)->execute($cart, afterCreate: function () use (&$thrown): void {
                $thrown++;

                throw Conflict::snapshot();
            });
        } catch (QueryException $exception) {
            expect($exception->getPrevious()?->errorInfo[1] ?? null)->toBe(1020);
        }

        expect($thrown)->toBe(1)
            ->and($this->runner->calls[0])->toBe('run:execute')
            ->and(Order::query()->count())->toBe(0)
            ->and(reservations())->toBe(0);
    });
});
