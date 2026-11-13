<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use InvalidArgumentException;
use Modules\Import\Domain\Strategies\AllocationStrategy;

/** Factory: resolves the allocation strategy for a cost basis. Strategies are injected (tagged in the container). */
final class AllocationStrategyFactory
{
    /** @var array<string,AllocationStrategy> */
    private array $byBasis = [];

    /** @param iterable<AllocationStrategy> $strategies */
    public function __construct(iterable $strategies)
    {
        foreach ($strategies as $strategy) {
            $this->byBasis[$strategy->basis()->value] = $strategy;
        }
    }

    public function for(CostBasis $basis): AllocationStrategy
    {
        return $this->byBasis[$basis->value]
            ?? throw new InvalidArgumentException("No allocation strategy registered for basis '{$basis->value}'.");
    }
}
