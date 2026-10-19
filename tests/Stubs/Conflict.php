<?php

declare(strict_types=1);

namespace Shopper\Api\Tests\Stubs;

use Illuminate\Database\QueryException;
use PDOException;

final class Conflict
{
    /**
     * The error MariaDB raises when innodb_snapshot_isolation finds the row
     * changed since the transaction snapshot.
     */
    public static function snapshot(): QueryException
    {
        $previous = new PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table');
        $previous->errorInfo = ['HY000', 1020, 'Record has changed since last read in table'];

        return new QueryException('testing', 'update stock', [], $previous);
    }
}
