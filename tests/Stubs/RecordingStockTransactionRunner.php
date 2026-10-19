<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Shopper\Core\Stock\DatabaseStockTransactionRunner;

/**
 * Records which method entered the runner and repeats a conflicted command
 * up to three times, like a host runner would.
 */
class RecordingStockTransactionRunner extends DatabaseStockTransactionRunner
{
    /** @var array<int, string> */
    public array $calls = [];

    public int $attempted = 0;

    public function run(callable $callback): mixed
    {
        $this->record('run');

        return parent::run($callback);
    }

    public function retry(Closure $command): mixed
    {
        $this->record('retry');

        return parent::retry($command);
    }

    protected function attempt(ConnectionInterface $connection, Closure $command): mixed
    {
        // Only the outermost attempts: a nested command joins its boundary
        if ($connection->transactionLevel() === 0) {
            $this->attempted++;
        }

        return parent::attempt($connection, $command);
    }

    protected function attempts(): int
    {
        return 3;
    }

    private function record(string $method): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? [];

        $this->calls[] = $method.':'.($caller['function'] ?? '?');
    }
}
