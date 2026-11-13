<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

/** Walks an ordered ladder of [limit, level]; the first rung whose limit covers the total wins. */
final class LadderApprovalPolicy implements ApprovalPolicy
{
    /** @param list<array{limit: ?string, level: int}> $ladder */
    public function __construct(private readonly array $ladder) {}

    public function requiredLevel(Fixed $total): int
    {
        $top = 1;
        foreach ($this->ladder as $rung) {
            $top = $rung['level'];
            if ($rung['limit'] === null || $total->lte(Fixed::parse($rung['limit']))) {
                return $rung['level'];
            }
        }

        return $top;
    }
}
