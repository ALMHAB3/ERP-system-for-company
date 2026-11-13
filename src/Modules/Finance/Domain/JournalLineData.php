<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class JournalLineData
{
    public function __construct(
        public string $accountCode,
        public Fixed $debit,
        public Fixed $credit,
        public ?string $memo = null,
    ) {}

    public static function debit(string $account, Fixed $amount, ?string $memo = null): self
    {
        return new self($account, $amount, Fixed::zero(), $memo);
    }

    public static function credit(string $account, Fixed $amount, ?string $memo = null): self
    {
        return new self($account, Fixed::zero(), $amount, $memo);
    }
}
