<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $table = 'users';
}
