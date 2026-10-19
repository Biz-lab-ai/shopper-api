<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * The core models carry no media relation of their own; the checkout
 * tests only need the relation the product observers clean up.
 */
class Media extends Model
{
    protected $guarded = [];

    protected $table = 'media';
}
