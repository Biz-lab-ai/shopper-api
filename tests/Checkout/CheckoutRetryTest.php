<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shopper\Api\Actions\CompleteCartAction;
use Shopper\Api\Actions\CreateCartPaymentSessionAction;
use Shopper\Api\Tests\Stubs\Conflict;
use Shopper\Api\Tests\Stubs\FakePaymentDriver;
use Shopper\Cart\Actions\CreateOrderFromCartAction;
use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartPromotion;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Zone;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Http\Exceptions\ApiValidationException;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;

beforeEach(function (): void {
    setupCurrencies();

    $country = Country::factory()->create(['cca2' => 'US']);
    $this->zone = Zone::factory()->create(['is_enabled' => true]);
    $this->zone->countries()->attach($country);

    $carrier = Carrier::factory()->create(['name' => 'Main Carrier', 'slug' => 'main-carrier', 'is_enabled' => true]);
    $this->zone->carriers()->attach($carrier);

    $this->option = CarrierOption::factory()->create([
        'name' => 'Standard',
        'price' => 100,
        'is_enabled' => true,
        'carrier_id' => $carrier->id,
        'zone_id' => $this->zone->id,
    ]);

    $this->inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $this->product = Product::factory()->standard()->publish()->create();
    $this->product->mutateStock($this->inventory->id, 100);
    $this->product->prices()->create([
        'amount' => 2500,
        'currency_id' => Currency::query()->where('code', 'USD')->value('id'),
    ]);

    $this->cart = Cart::factory()->create(['currency_code' => 'USD', 'email' => 'john@example.com', 'zone_id' => $this->zone->id]);
    $this->cart->lines()->create([
        'purchasable_type' => $this->product->getMorphClass(),
        'purchasable_id' => $this->product->id,
        'quantity' => 1,
        'unit_price_amount' => 2500,
    ]);
    $this->cart->addresses()->create([
        'type' => AddressType::Shipping,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'address_1' => '1 Main Street',
        'city' => 'New York',
        'postal_code' => '10001',
    ]);
    $this->cart->update([
        'shipping_option_id' => "main-carrier:{$this->option->public_id}",
        'shipping_amount' => $this->option->price,
    ]);

    $driver = $this->driver = new FakePaymentDriver;
    Payment::extend('fake', fn (): FakePaymentDriver => $driver);
    $method = PaymentMethod::factory()->create(['title' => 'Card', 'is_enabled' => true, 'driver' => 'fake']);
    $method->zones()->attach($this->zone);
    $this->cart->update(['payment_method_id' => $method->id]);

    $this->runner = recordingStockRunner();
});

function openPaymentSession(Cart $cart): void
{
    app(CreateCartPaymentSessionAction::class)->execute($cart->refresh());
}

function completeCart(Cart $cart): Order
{
    /** @var Order $order */
    $order = app(CompleteCartAction::class)->execute(Cart::query()->findOrFail($cart->id));

    return $order;
}

function checkoutCode(Closure $complete): ?ErrorCode
{
    try {
        $complete();
    } catch (ApiValidationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

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

function reservations(): int
{
    return InventoryHistory::query()->where('reference_type', (new Order)->getMorphClass())->count();
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
