<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Customer;
use Modules\Sales\Domain\CustomerCategory;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Shared\Domain\Fixed;

final class DbCustomerRepository implements CustomerRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(int $id): ?Customer
    {
        $c = $this->db->table('customers')->where('id', $id)->first();

        return $c ? new Customer((int) $c->id, $c->name, CustomerCategory::from($c->category), Fixed::units((int) $c->credit_limit), (bool) $c->is_walk_in) : null;
    }
}
