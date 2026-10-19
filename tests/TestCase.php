<?php

declare(strict_types=1);

namespace Shopper\Api\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use Shopper\Api\ApiServiceProvider;
use Shopper\Cart\CartServiceProvider;
use Shopper\Core\CoreServiceProvider;
use Shopper\Core\Database\Seeders\CurrenciesTableSeeder;
use Shopper\Core\Models;
use Shopper\Http\HttpServiceProvider;
use Shopper\Payment\PaymentServiceProvider;
use Shopper\Shipping\ShippingServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * The store routes switch of the next application, null for the default.
     */
    public static ?bool $apiRoutes = null;

    /**
     * The checkout repeats only from the outermost transaction level, so the
     * tests run committed, like a request, and empty the tables after.
     *
     * @var array<int, string>
     */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Models\Product::class, Models\ProductVariant::class] as $model) {
            $model::resolveRelationUsing('media', static fn ($owner) => $owner->morphMany(Stubs\Media::class, 'model'));
        }

        $this->seed(CurrenciesTableSeeder::class);
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CoreServiceProvider::class,
            CartServiceProvider::class,
            PaymentServiceProvider::class,
            ShippingServiceProvider::class,
            MediaLibraryServiceProvider::class,
            HttpServiceProvider::class,
            ApiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'timezone' => '+00:00',
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ]);

        // A file, not :memory:, so the committed tests keep one schema
        $sqlite = sys_get_temp_dir().'/shopper-api-checkout-tests.sqlite';
        touch($sqlite);
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $sqlite,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        $app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(__DIR__.'/database/migrations');
            $migrator->path(dirname(__DIR__).'/vendor/shopper/core/database/migrations');
            $migrator->path(dirname(__DIR__).'/vendor/shopper/cart/database/migrations');
        });
        $app['config']->set('scout.driver', null);

        if (self::$apiRoutes !== null) {
            $app['config']->set('shopper.api.routes', self::$apiRoutes);
        }
        $app['config']->set('auth.providers.users.model', Stubs\User::class);
        $app['config']->set('shopper.models', [
            'address' => Models\Address::class,
            'brand' => Models\Brand::class,
            'category' => Models\Category::class,
            'collection' => Models\Collection::class,
            'product' => Models\Product::class,
            'variant' => Models\ProductVariant::class,
            'attribute_product' => Models\AttributeProduct::class,
            'channel' => Models\Channel::class,
            'inventory' => Models\Inventory::class,
            'order' => Models\Order::class,
            'supplier' => Models\Supplier::class,
            'tax_zone' => Models\TaxZone::class,
            'tax_rate' => Models\TaxRate::class,
            'customer' => Stubs\User::class,
            'customer_title_column' => 'name',
        ]);
    }
}
