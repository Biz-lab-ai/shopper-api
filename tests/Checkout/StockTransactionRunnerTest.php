<?php

declare(strict_types=1);

use Shopper\Api\Actions\CompleteCartAction;
use Shopper\Cart\Actions\CreateOrderFromCartAction;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Zone;

beforeEach(function (): void {
    setupCurrencies();

    $zone = Zone::factory()->create(['is_enabled' => true]);
    $inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $product = Product::factory()->virtual()->publish()->create();
    $product->mutateStock($inventory->id, 10);
    $product->prices()->create(['amount' => 1000, 'currency_id' => Currency::query()->where('code', 'USD')->value('id')]);
    $method = PaymentMethod::factory()->create(['title' => 'Cash', 'is_enabled' => true, 'driver' => 'manual']);
    $method->zones()->attach($zone);

    $this->cart = Cart::factory()->create(['currency_code' => 'USD', 'email' => 'jane@example.com', 'zone_id' => $zone->id, 'payment_method_id' => $method->id]);
    $this->cart->lines()->create([
        'purchasable_type' => $product->getMorphClass(),
        'purchasable_id' => $product->id,
        'quantity' => 1,
        'unit_price_amount' => 1000,
    ]);

    $this->runner = recordingStockRunner();
});

describe('StockTransactionRunnerTest', function (): void {
    it('creates an order from a cart through the runner run, without a retry', function (): void {
        app(CreateOrderFromCartAction::class)->execute(Cart::query()->findOrFail($this->cart->id));

        expect($this->runner->calls[0])->toBe('run:execute');
    });

    it('completes a cart through the runner retry', function (): void {
        app(CompleteCartAction::class)->execute(Cart::query()->findOrFail($this->cart->id));

        expect($this->runner->calls[0])->toBe('retry:complete');
    });
});
