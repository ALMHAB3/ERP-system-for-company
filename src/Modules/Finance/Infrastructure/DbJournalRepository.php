<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Domain\Fixed;

final class DbJournalRepository implements JournalRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function findIdBySource(string $sourceType, string $sourceId, string $rule): ?int
    {
        $id = $this->db->table('journal_entries')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->where('rule', $rule)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function missingAccounts(array $codes): array
    {
        $found = $this->db->table('accounts')->whereIn('code', $codes)->pluck('code')->all();

        return array_values(array_diff($codes, $found));
    }

    public function insert(JournalEntryData $entry, ?int $reversesEntryId = null): int
    {
        $id = $this->db->table('journal_entries')->insertGetId([
            'description' => $entry->description,
            'source_type' => $entry->sourceType,
            'source_id' => $entry->sourceId,
            'rule' => $entry->rule,
            'reverses_entry_id' => $reversesEntryId,
            'posted_at' => ($entry->postedAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
            'created_at' => now(),
        ]);
        $this->db->table('journal_lines')->insert(array_map(static fn (JournalLineData $l) => [
            'journal_entry_id' => $id,
            'account_code' => $l->accountCode,
            'debit' => $l->debit->units,
            'credit' => $l->credit->units,
            'memo' => $l->memo,
            'currency' => $l->amountCurrency === null ? null : $l->currency,
            'amount_currency' => $l->currency === null ? null : $l->signedAmountCurrency()?->units,
        ], $entry->lines));

        return $id;
    }

    public function foreignCurrencyAccounts(array $codes, string $baseCurrency): array
    {
        return $this->db->table('bank_accounts')->whereIn('gl_account', $codes)->where('currency', '!=', $baseCurrency)->pluck('currency', 'gl_account')->all();
    }

    public function trialBalance(?DateTimeImmutable $asOf = null): array
    {
        $lines = $this->db->table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->when($asOf !== null, fn ($q) => $q->where('je.posted_at', '<=', $asOf->format('Y-m-d').' 23:59:59'))
            ->select('jl.account_code', 'jl.debit', 'jl.credit');

        return $this->db->table('accounts as a')
            ->leftJoinSub($lines, 'l', 'l.account_code', '=', 'a.code')
            ->groupBy('a.code', 'a.name')->orderBy('a.code')
            ->selectRaw('a.code as account_code, a.name, COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->get()
            ->map(static fn ($r) => [
                'account_code' => $r->account_code,
                'name' => $r->name,
                'debit' => (string) Fixed::units((int) $r->debit),
                'credit' => (string) Fixed::units((int) $r->credit),
            ])->all();
    }

    public function accountStatement(string $accountCode, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $account = $this->db->table('accounts')->where('code', $accountCode)->first(['code', 'name', 'type']);
        if (! $account) {
            return null;
        }
        $lines = fn () => $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.account_code', $accountCode);
        $opening = (int) $lines()->where('e.posted_at', '<', $from->format('Y-m-d').' 00:00:00')->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as net')->value('net');
        $balance = $opening;
        $rows = [];
        foreach ($lines()->whereBetween('e.posted_at', [$from->format('Y-m-d').' 00:00:00', $to->format('Y-m-d').' 23:59:59'])->orderBy('e.posted_at')->orderBy('l.id')
            ->get(['l.id', 'l.debit', 'l.credit', 'l.memo', 'l.currency', 'l.amount_currency', 'e.id as entry_id', 'e.description', 'e.posted_at', 'e.source_type', 'e.source_id', 'e.rule']) as $l) {
            $balance += (int) $l->debit - (int) $l->credit;
            $rows[] = [
                'line_id' => (int) $l->id, 'entry_id' => (int) $l->entry_id, 'date' => substr((string) $l->posted_at, 0, 10), 'description' => $l->description, 'memo' => $l->memo,
                'source' => $l->source_type.':'.$l->source_id, 'rule' => $l->rule,
                'debit' => (string) Fixed::units((int) $l->debit), 'credit' => (string) Fixed::units((int) $l->credit),
                'currency' => $l->currency, 'amount_currency' => $l->amount_currency === null ? null : (string) Fixed::units((int) $l->amount_currency),
                'balance' => (string) Fixed::units($balance),
            ];
        }

        return ['account' => ['code' => $account->code, 'name' => $account->name, 'type' => $account->type], 'opening' => (string) Fixed::units($opening), 'closing' => (string) Fixed::units($balance), 'lines' => $rows];
    }

    public function find(int $id): ?JournalEntryData
    {
        $e = $this->db->table('journal_entries')->where('id', $id)->first();
        if (! $e) {
            return null;
        }
        $lines = $this->db->table('journal_lines')->where('journal_entry_id', $id)->orderBy('id')->get()
            ->map(static fn ($l) => new JournalLineData($l->account_code, Fixed::units((int) $l->debit), Fixed::units((int) $l->credit), $l->memo,
                $l->currency, $l->amount_currency === null ? null : Fixed::units(abs((int) $l->amount_currency)), (int) $l->id))->all();

        return new JournalEntryData($e->description, $lines, $e->source_type, $e->source_id, $e->rule, new DateTimeImmutable($e->posted_at));
    }
}
