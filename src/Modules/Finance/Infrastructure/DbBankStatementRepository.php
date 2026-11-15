<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Finance\Domain\BankStatementRepository;

final class DbBankStatementRepository implements BankStatementRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function bankAccount(int $id): ?object
    {
        return $this->db->table('bank_accounts')->where('id', $id)->first(['id', 'name', 'currency', 'gl_account', 'active']);
    }

    public function lastClosingBalance(int $bankAccountId): ?int
    {
        $value = $this->db->table('bank_statements')->where('bank_account_id', $bankAccountId)->orderByDesc('statement_date')->orderByDesc('id')->value('closing_balance');

        return $value === null ? null : (int) $value;
    }

    public function insertStatement(int $bankAccountId, string $reference, string $date, int $opening, int $closing, array $lines, ?int $userId): int
    {
        $id = $this->db->table('bank_statements')->insertGetId([
            'bank_account_id' => $bankAccountId, 'reference' => $reference, 'statement_date' => $date, 'opening_balance' => $opening, 'closing_balance' => $closing,
            'status' => 'open', 'created_by' => $userId, 'created_at' => now(),
        ]);
        $this->db->table('bank_statement_lines')->insert(array_map(static fn (array $l) => $l + ['bank_statement_id' => $id], $lines));

        return $id;
    }

    public function statements(?int $bankAccountId): array
    {
        return $this->statementQuery()->when($bankAccountId !== null, fn ($q) => $q->where('s.bank_account_id', $bankAccountId))
            ->orderByDesc('s.statement_date')->orderByDesc('s.id')->limit(200)->get()->all();
    }

    public function statement(int $id, bool $lock = false): ?object
    {
        if ($lock) {
            $this->db->table('bank_statements')->where('id', $id)->lockForUpdate()->first();
        }

        return $this->statementQuery()->where('s.id', $id)->first();
    }

    public function lines(int $statementId): array
    {
        return $this->db->table('bank_statement_lines as sl')
            ->leftJoin('journal_lines as jl', 'jl.id', '=', 'sl.journal_line_id')->leftJoin('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('sl.bank_statement_id', $statementId)->orderBy('sl.line_date')->orderBy('sl.id')
            ->get(['sl.id', 'sl.line_date', 'sl.description', 'sl.amount', 'sl.journal_line_id', 'sl.matched_at', 'je.id as entry_id', 'je.description as entry_description', 'je.posted_at as entry_date'])->all();
    }

    public function line(int $lineId, bool $lock = false): ?object
    {
        if ($lock) {
            $this->db->table('bank_statement_lines')->where('id', $lineId)->lockForUpdate()->first();
        }

        return $this->db->table('bank_statement_lines as sl')->join('bank_statements as s', 's.id', '=', 'sl.bank_statement_id')->join('bank_accounts as b', 'b.id', '=', 's.bank_account_id')
            ->where('sl.id', $lineId)
            ->first(['sl.id', 'sl.bank_statement_id', 'sl.line_date', 'sl.description', 'sl.amount', 'sl.journal_line_id', 's.status', 's.statement_date', 'b.currency', 'b.gl_account', 'b.name as bank_name']);
    }

    public function unmatchedLedgerLines(string $glAccount, ?string $foreignCurrency, ?DateTimeImmutable $upTo = null, ?int $amount = null): array
    {
        $expr = $this->amountExpression($foreignCurrency);
        $query = $this->ledger($glAccount, $foreignCurrency)
            ->whereNotIn('l.id', fn ($q) => $q->from('bank_statement_lines')->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->whereRaw("{$expr} <> 0");
        if ($upTo !== null) {
            $query->where('e.posted_at', '<=', $upTo->format('Y-m-d').' 23:59:59');
        }
        if ($amount !== null) {
            $query->whereRaw("{$expr} = ?", [$amount]);
        }

        return $query->orderBy('e.posted_at')->orderBy('l.id')->limit(500)
            ->selectRaw("l.id, e.posted_at, e.description, l.memo, {$expr} as amount")->get()
            ->map(static fn ($r) => (object) ['id' => (int) $r->id, 'date' => substr((string) $r->posted_at, 0, 10), 'description' => $r->description, 'memo' => $r->memo, 'amount' => (int) $r->amount])->all();
    }

    public function ledgerBalance(string $glAccount, ?string $foreignCurrency, DateTimeImmutable $asOf): int
    {
        return (int) $this->ledger($glAccount, $foreignCurrency)->where('e.posted_at', '<=', $asOf->format('Y-m-d').' 23:59:59')
            ->selectRaw("COALESCE(SUM({$this->amountExpression($foreignCurrency)}), 0) as balance")->value('balance');
    }

    public function ledgerLineId(string $sourceType, string $sourceId, string $rule, string $glAccount): ?int
    {
        $id = $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.source_type', $sourceType)->where('e.source_id', $sourceId)->where('e.rule', $rule)->where('l.account_code', $glAccount)->value('l.id');

        return $id === null ? null : (int) $id;
    }

    public function setMatch(int $lineId, ?int $journalLineId, ?int $userId): void
    {
        $this->db->table('bank_statement_lines')->where('id', $lineId)->update([
            'journal_line_id' => $journalLineId, 'matched_by' => $journalLineId === null ? null : $userId, 'matched_at' => $journalLineId === null ? null : now(),
        ]);
    }

    public function markReconciled(int $statementId, ?int $userId): void
    {
        $this->db->table('bank_statements')->where('id', $statementId)->update(['status' => 'reconciled', 'reconciled_by' => $userId, 'reconciled_at' => now()]);
    }

    private function statementQuery(): Builder
    {
        return $this->db->table('bank_statements as s')->join('bank_accounts as b', 'b.id', '=', 's.bank_account_id')
            ->select(['s.id', 's.bank_account_id', 's.reference', 's.statement_date', 's.opening_balance', 's.closing_balance', 's.status', 's.reconciled_at', 'b.name as bank_name', 'b.currency', 'b.gl_account'])
            ->selectSub(fn ($q) => $q->from('bank_statement_lines')->whereColumn('bank_statement_id', 's.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn ($q) => $q->from('bank_statement_lines')->whereColumn('bank_statement_id', 's.id')->whereNull('journal_line_id')->selectRaw('COUNT(*)'), 'unmatched_count');
    }

    /** Ledger lines of one bank account; for a foreign account only the lines that carry that currency. */
    private function ledger(string $glAccount, ?string $foreignCurrency): Builder
    {
        return $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_code', $glAccount)->when($foreignCurrency !== null, fn ($q) => $q->where('l.currency', $foreignCurrency));
    }

    private function amountExpression(?string $foreignCurrency): string
    {
        return $foreignCurrency === null ? '(l.debit - l.credit)' : 'l.amount_currency';
    }
}
