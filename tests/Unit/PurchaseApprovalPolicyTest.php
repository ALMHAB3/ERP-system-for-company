<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Procurement\Domain\LadderApprovalPolicy;
use Modules\Shared\Domain\Fixed;
use PHPUnit\Framework\TestCase;

final class PurchaseApprovalPolicyTest extends TestCase
{
    public function test_ladder_picks_the_lowest_sufficient_level(): void
    {
        $policy = new LadderApprovalPolicy([
            ['limit' => '10000.0000', 'level' => 1],
            ['limit' => '100000.0000', 'level' => 2],
            ['limit' => null, 'level' => 3],
        ]);
        $this->assertSame(1, $policy->requiredLevel(Fixed::parse('10000')));
        $this->assertSame(2, $policy->requiredLevel(Fixed::parse('10000.0001')));
        $this->assertSame(3, $policy->requiredLevel(Fixed::parse('5000000')));
    }
}
