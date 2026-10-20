<?php

declare(strict_types=1);

namespace Shopper\Api\Http\Requests\Cart;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Shopper\Api\Concerns\NormalizesCartInput;
use Shopper\Core\Models\Zone;

final class PatchCartRequest extends FormRequest
{
    use NormalizesCartInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'currency_code' => ['sometimes', 'required', 'string', $this->currencyExistsRule()],
            'zone_code' => ['sometimes', 'required', 'string', $this->zoneExistsRule()],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'metadata' => ['sometimes', 'nullable', ...$this->metadataRules()],
        ];
    }

    /**
     * The zone is looked up through the model, never the raw table: a host
     * that scopes Zone (a tenant fence) lets only the zones the model sees
     * be chosen, so the code of a foreign zone is refused here instead of
     * turning into zone_id 0 in UpdateCartAction.
     */
    protected function zoneExistsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Zone::query()->where('code', $value)->where('is_enabled', true)->exists()) {
                $fail('validation.exists')->translate();
            }
        };
    }
}
