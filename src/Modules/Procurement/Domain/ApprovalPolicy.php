<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

/** Strategy: which approval level an order of a given base-currency total needs. */
interface ApprovalPolicy
{
    public function requiredLevel(Fixed $total): int;
}
