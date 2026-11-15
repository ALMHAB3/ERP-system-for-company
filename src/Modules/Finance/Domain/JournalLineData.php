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
        /** Foreign currency of the line, when the amount was really in another currency. */
        public ?string $currency = null,
        /** Amount in that currency, as a positive magnitude; the side (debit/credit) gives the sign. May be zero (a pure revaluation). */
        public ?Fixed $amountCurrency = null,
        /** Database id; set only on lines read back from the ledger. */
        public ?int $id = null,
        /** Branch (warehouse) the line belongs to; null = head office. Usually filled from the source document. */
        public ?int $branchId = null,
    ) {}

    public function forBranch(?int $branchId): self
    {
        return new self($this->accountCode, $this->debit, $this->credit, $this->memo, $this->currency, $this->amountCurrency, $this->id, $branchId);
    }

    public static function debit(string $account, Fixed $amount, ?string $memo = null, ?string $currency = null, ?Fixed $amountCurrency = null, ?int $branchId = null): self
    {
        return new self($account, $amount, Fixed::zero(), $memo, $currency, $amountCurrency, null, $branchId);
    }

    public static function credit(string $account, Fixed $amount, ?string $memo = null, ?string $currency = null, ?Fixed $amountCurrency = null, ?int $branchId = null): self
    {
        return new self($account, Fixed::zero(), $amount, $memo, $currency, $amountCurrency, null, $branchId);
    }

    public function inCurrency(string $currency, Fixed $amount): self
    {
        return new self($this->accountCode, $this->debit, $this->credit, $this->memo, $currency, $amount->abs(), $this->id, $this->branchId);
    }

    /** Signed foreign amount as stored: + on a debit line, - on a credit line. */
    public function signedAmountCurrency(): ?Fixed
    {
        if ($this->amountCurrency === null) {
            return null;
        }

        return $this->debit->isPositive() ? $this->amountCurrency->abs() : $this->amountCurrency->abs()->neg();
    }
}
