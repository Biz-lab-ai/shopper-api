<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Closure;
use Illuminate\Cache\ArrayStore;

/**
 * An array store whose lock, refused once, runs the hook of the test: the
 * holder of the lock is another process, finishing its work before the
 * waiting call tries again.
 */
final class ContendedArrayStore extends ArrayStore
{
    public ?Closure $onConflict = null;

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new ContendedArrayLock($this, $name, $seconds, $owner);
    }

    public function conflict(): void
    {
        $hook = $this->onConflict;
        $this->onConflict = null;

        if ($hook !== null) {
            $hook();
        }
    }
}
