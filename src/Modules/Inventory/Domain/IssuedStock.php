<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use Modules\Shared\Domain\Fixed;

/** Result of an outbound movement: one ledger line per lot consumed (FEFO), each at its own lot cost. */
final readonly class IssuedStock
{
    /** @param list<StockMove> $moves */
    public function __construct(public array $moves) {}

    public function totalCost(): Fixed
    {
        return array_reduce($this->moves, static fn (Fixed $c, StockMove $m) => $c->add($m->totalCost), Fixed::zero());
    }

    public function quantity(): Fixed
    {
        return array_reduce($this->moves, static fn (Fixed $c, StockMove $m) => $c->add($m->quantity), Fixed::zero());
    }
}
