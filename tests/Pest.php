<?php

declare(strict_types=1);

use Shopper\Api\Actions\CompleteCartAction;
use Shopper\Api\Actions\CreateCartPaymentSessionAction;
use Shopper\Api\Tests\Stubs\FakePaymentDriver;
use Shopper\Api\Tests\Stubs\RecordingStockTransactionRunner;
use Shopper\Api\Tests\TestCase;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Http\Exceptions\ApiValidationException;
use Shopper\Payment\Facades\Payment;

pest()->extend(TestCase::class)->in(__DIR__);

function recordingStockRunner(): RecordingStockTransactionRunner
{
    $runner = new RecordingStockTransactionRunner;

    app()->instance(StockTransactionRunner::class, $runner);

    return $runner;
}

function setupCurrencies(string $code = 'USD'): void
{
    $currency = Currency::query()->where('code', $code)->firstOrFail();

    Setting::query()->updateOrCreate(
        ['key' => 'currencies'],
        ['value' => [$currency->id], 'display_name' => Setting::lockedAttributesDisplayName('currencies'), 'locked' => true],
    );
    Setting::query()->updateOrCreate(
        ['key' => 'default_currency_id'],
        ['value' => $currency->id, 'display_name' => Setting::lockedAttributesDisplayName('default_currency_id'), 'locked' => true],
    );
}

/**
 * A cart of one product in stock, shipped and paid with the fake driver.
 */
function seedCheckoutCart(object $test): void
{
    setupCurrencies();

    $country = Country::factory()->create(['cca2' => 'US']);
    $test->zone = Zone::factory()->create(['is_enabled' => true]);
    $test->zone->countries()->attach($country);

    $carrier = Carrier::factory()->create(['name' => 'Main Carrier', 'slug' => 'main-carrier', 'is_enabled' => true]);
    $test->zone->carriers()->attach($carrier);

    $test->option = CarrierOption::factory()->create([
        'name' => 'Standard',
        'price' => 100,
        'is_enabled' => true,
        'carrier_id' => $carrier->id,
        'zone_id' => $test->zone->id,
    ]);

    $test->inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $test->product = Product::factory()->standard()->publish()->create();
    $test->product->mutateStock($test->inventory->id, 100);
    $test->product->prices()->create([
        'amount' => 2500,
        'currency_id' => Currency::query()->where('code', 'USD')->value('id'),
    ]);

    $test->cart = Cart::factory()->create(['currency_code' => 'USD', 'email' => 'john@example.com', 'zone_id' => $test->zone->id]);
    $test->cart->lines()->create([
        'purchasable_type' => $test->product->getMorphClass(),
        'purchasable_id' => $test->product->id,
        'quantity' => 1,
        'unit_price_amount' => 2500,
    ]);
    $test->cart->addresses()->create([
        'type' => AddressType::Shipping,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'address_1' => '1 Main Street',
        'city' => 'New York',
        'postal_code' => '10001',
    ]);
    $test->cart->update([
        'shipping_option_id' => "main-carrier:{$test->option->public_id}",
        'shipping_amount' => $test->option->price,
    ]);

    $driver = $test->driver = new FakePaymentDriver;
    Payment::extend('fake', fn (): FakePaymentDriver => $driver);
    $method = PaymentMethod::factory()->create(['title' => 'Card', 'is_enabled' => true, 'driver' => 'fake']);
    $method->zones()->attach($test->zone);
    $test->cart->update(['payment_method_id' => $method->id]);

    $test->runner = recordingStockRunner();
}

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

function reservations(): int
{
    return InventoryHistory::query()->where('reference_type', (new Order)->getMorphClass())->count();
}
