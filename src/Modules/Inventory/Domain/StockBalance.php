<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use Modules\Shared\Domain\Fixed;

/** Derived projection of the ledger for one product in one warehouse. Never edited by hand. */
final readonly class StockBalance
{
    public function __construct(
        public Fixed $onHand,
        public Fixed $reserved,
        public Fixed $value,
    ) {}

    public static function empty(): self
    {
        return new self(Fixed::zero(), Fixed::zero(), Fixed::zero());
    }

    /** Available to promise = physically on hand minus reserved. */
    public function available(): Fixed
    {
        return $this->onHand->sub($this->reserved);
    }

    public function averageCost(): ?Fixed
    {
        return $this->onHand->isPositive() ? $this->value->div($this->onHand) : null;
    }

    public function with(?Fixed $onHand = null, ?Fixed $reserved = null, ?Fixed $value = null): self
    {
        return new self($onHand ?? $this->onHand, $reserved ?? $this->reserved, $value ?? $this->value);
    }
}
