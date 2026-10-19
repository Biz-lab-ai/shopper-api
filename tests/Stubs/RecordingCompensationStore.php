<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Shopper\Payment\Contracts\CompensationStore;

final class RecordingCompensationStore implements CompensationStore
{
    /** @var array<string, array{driver: string, amount: int, cause: string}> */
    public static array $decisions = [];

    public function begin(string $driver, string $reference, int $amount, string $cause): bool
    {
        if (isset(self::$decisions[$reference])) {
            return false;
        }

        self::$decisions[$reference] = ['driver' => $driver, 'amount' => $amount, 'cause' => $cause];

        return true;
    }

    public function compensated(string $reference): bool
    {
        return isset(self::$decisions[$reference]);
    }
}
