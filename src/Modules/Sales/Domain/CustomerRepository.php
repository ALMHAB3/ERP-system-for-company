<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

interface CustomerRepository
{
    public function find(int $id): ?Customer;
}
