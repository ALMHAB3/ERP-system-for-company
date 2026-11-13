<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

interface TransactionRunner
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed;
}
