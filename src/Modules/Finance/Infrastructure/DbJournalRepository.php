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
        ], $entry->lines));

        return $id;
    }

    public function trialBalance(): array
    {
        return $this->db->table('accounts as a')
            ->leftJoin('journal_lines as l', 'l.account_code', '=', 'a.code')
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

    public function find(int $id): ?JournalEntryData
    {
        $e = $this->db->table('journal_entries')->where('id', $id)->first();
        if (! $e) {
            return null;
        }
        $lines = $this->db->table('journal_lines')->where('journal_entry_id', $id)->orderBy('id')->get()
            ->map(static fn ($l) => new JournalLineData($l->account_code, Fixed::units((int) $l->debit), Fixed::units((int) $l->credit), $l->memo))->all();

        return new JournalEntryData($e->description, $lines, $e->source_type, $e->source_id, $e->rule, new DateTimeImmutable($e->posted_at));
    }
}
