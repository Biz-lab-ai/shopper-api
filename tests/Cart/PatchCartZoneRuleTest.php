<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Shopper\Api\Http\Requests\Cart\PatchCartRequest;
use Shopper\Core\Models\Zone;

/**
 * The zone_code rule of the cart patch reads the zones THROUGH THE MODEL,
 * so a host that scopes Zone (a tenant fence) decides which codes exist:
 * the code of a zone the model does not see is refused by validation and
 * never reaches UpdateCartAction, where it would become zone_id 0.
 */
function zoneCodeErrors(string $code): array
{
    $rules = (new PatchCartRequest)->rules();

    return Validator::make(['zone_code' => $code], ['zone_code' => $rules['zone_code']])->errors()->all();
}

afterEach(function (): void {
    Zone::clearBootedModels();
});

describe('PatchCartZoneRuleTest', function (): void {
    it('accepts the code of an enabled zone the model sees', function (): void {
        $zone = Zone::factory()->create(['code' => 'eu', 'is_enabled' => true]);

        expect(zoneCodeErrors($zone->code))->toBe([]);
    });

    it('refuses the code of a disabled zone', function (): void {
        Zone::factory()->create(['code' => 'off', 'is_enabled' => false]);

        expect(zoneCodeErrors('off'))->not->toBe([]);
    });

    it('refuses the code of an enabled zone the model is scoped away from', function (): void {
        $visible = Zone::factory()->create(['code' => 'mine', 'is_enabled' => true]);
        Zone::factory()->create(['code' => 'theirs', 'is_enabled' => true]);

        Zone::addGlobalScope('fence', fn (Builder $query) => $query->whereKey($visible->id));

        expect(zoneCodeErrors('mine'))->toBe([])
            ->and(zoneCodeErrors('theirs'))->not->toBe([]);
    });
});
