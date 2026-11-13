<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $name,
        public CustomerCategory $category,
        public Fixed $creditLimit,
        public bool $isWalkIn = false,
    ) {}
}
