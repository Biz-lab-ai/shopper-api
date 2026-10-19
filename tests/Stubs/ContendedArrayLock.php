<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Illuminate\Cache\ArrayLock;

final class ContendedArrayLock extends ArrayLock
{
    public function __construct(private readonly ContendedArrayStore $contended, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($contended, $name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        if (parent::acquire()) {
            return true;
        }

        $this->contended->conflict();

        return false;
    }
}
