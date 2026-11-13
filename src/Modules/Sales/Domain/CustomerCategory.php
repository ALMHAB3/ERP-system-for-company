<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

enum CustomerCategory: string
{
    case Wholesale = 'wholesale';
    case Retail = 'retail';
    case Direct = 'direct';
}
