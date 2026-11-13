<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

final readonly class Supplier
{
    public function __construct(public int $id, public string $name, public string $currency, public int $termsDays) {}
}
