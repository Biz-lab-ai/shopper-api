<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Shopper\Api\Tests\TestCase;

function storeRoutes(): int
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->getActionName(), 'Shopper\\Api\\'))
        ->count();
}

afterEach(function (): void {
    TestCase::$apiRoutes = null;
});

describe('ApiRoutesSwitchTest', function (): void {
    it('registers the store routes by default', function (): void {
        expect(config('shopper.api.routes'))->toBeTrue()
            ->and(storeRoutes())->toBeGreaterThan(0);
    });

    it('registers no store route when the switch is off', function (): void {
        TestCase::$apiRoutes = false;
        $this->refreshApplication();

        expect(config('shopper.api.routes'))->toBeFalse()
            ->and(storeRoutes())->toBe(0);
    });

    it('registers the store routes when the switch is on', function (): void {
        TestCase::$apiRoutes = true;
        $this->refreshApplication();

        expect(storeRoutes())->toBeGreaterThan(0);
    });
});
