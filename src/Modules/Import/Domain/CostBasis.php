<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

enum CostBasis: string
{
    case Value = 'value';
    case Volume = 'volume';
    case Weight = 'weight';
    case Quantity = 'quantity';
}
