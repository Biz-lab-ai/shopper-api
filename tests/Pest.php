<?php

declare(strict_types=1);

use Shopper\Api\Tests\Stubs\RecordingStockTransactionRunner;
use Shopper\Api\Tests\TestCase;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Setting;

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
