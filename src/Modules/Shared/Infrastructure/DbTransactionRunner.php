<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Shared\Application\TransactionRunner;

final class DbTransactionRunner implements TransactionRunner
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function run(callable $work): mixed
    {
        // 3 attempts: retries serialization failures / deadlocks when this is the outermost transaction.
        return $this->db->transaction($work, 3);
    }
}
