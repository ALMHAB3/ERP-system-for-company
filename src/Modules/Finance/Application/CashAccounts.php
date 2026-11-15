<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Application\AuditLogger;
use Modules\Shared\Domain\Fixed;

/** Where money goes in and out: the cash till, or a specific bank account (each with its own ledger account). */
final class CashAccounts
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLogger $audit) {}

    /** 'bank' was the old name of a bank transfer. */
    public static function normalize(string $method): string
    {
        return $method === 'bank' ? 'transfer' : $method;
    }

    /** Ledger account for a payment method: cash -> the till; transfer/cheque -> the chosen bank account. */
    public function resolve(string $method, ?int $bankAccountId): string
    {
        $method = self::normalize($method);
        if ($method === 'cash') {
            return (string) config('erp.accounts.cash');
        }
        if ($bankAccountId === null) {
            throw new DomainException('Choose the bank account the money goes through.');
        }
        $gl = $this->db->table('bank_accounts')->where('id', $bankAccountId)->where('active', true)->value('gl_account');

        return $gl === null ? throw new DomainException("Bank account {$bankAccountId} does not exist or is inactive.") : (string) $gl;
    }

    public function create(string $name, ?string $accountNumber, string $currency): int
    {
        return $this->db->transaction(function () use ($name, $accountNumber, $currency): int {
            $last = $this->db->table('bank_accounts')->max('gl_account');
            $code = $last === null ? 1120 : ((int) $last) + 1;
            if ($code > 1199) {
                throw new DomainException('No more bank ledger accounts available (1120-1199).');
            }
            $this->db->table('accounts')->insert(['code' => (string) $code, 'name' => 'Bank - '.$name, 'type' => 'asset', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $id = $this->db->table('bank_accounts')->insertGetId(['name' => $name, 'account_number' => $accountNumber, 'currency' => strtoupper($currency), 'gl_account' => (string) $code, 'active' => true, 'created_at' => now()]);
            $this->audit->record('bank_account.created', 'bank_account', $id, ['name' => $name, 'gl_account' => $code]);

            return $id;
        });
    }

    /** Cash till and every bank account with its current ledger balance. @return list<array<string,mixed>> */
    public function balances(?array $branches = null): array
    {
        $cash = (string) config('erp.accounts.cash');
        // Each branch's till = the movements of the cash account that belong to that branch.
        $tills = $this->db->table('journal_lines')->where('account_code', $cash)
            ->when($branches !== null, fn ($q) => $q->whereIn('warehouse_id', $branches === [] ? [0] : $branches))
            ->groupBy('warehouse_id')->selectRaw('warehouse_id, sum(debit) - sum(credit) as net')->get();
        $names = $this->db->table('warehouses')->pluck('name', 'id');
        $byBranch = $tills->map(fn ($t) => ['branch_id' => $t->warehouse_id === null ? null : (int) $t->warehouse_id,
            'branch' => $t->warehouse_id === null ? null : ($names[$t->warehouse_id] ?? null), 'balance' => (string) Fixed::units((int) $t->net)])->values()->all();
        if ($branches !== null) {
            // Branch staff see only their own till(s), never the company's bank accounts.
            return array_map(fn (int $b) => ['id' => null, 'kind' => 'till', 'name' => $names[$b] ?? null, 'branch_id' => $b, 'account_number' => null, 'currency' => config('erp.base_currency'),
                'gl_account' => $cash, 'active' => true, 'balance' => collect($byBranch)->firstWhere('branch_id', $b)['balance'] ?? '0.0000'], $branches);
        }

        $rows = [['id' => null, 'kind' => 'cash', 'name' => null, 'account_number' => null, 'currency' => config('erp.base_currency'), 'gl_account' => (string) config('erp.accounts.cash'), 'active' => true]];
        foreach ($this->db->table('bank_accounts')->orderBy('id')->get() as $b) {
            $rows[] = ['id' => (int) $b->id, 'kind' => 'bank', 'name' => $b->name, 'account_number' => $b->account_number, 'currency' => $b->currency, 'gl_account' => $b->gl_account, 'active' => (bool) $b->active];
        }
        $sums = $this->db->table('journal_lines')->whereIn('account_code', array_column($rows, 'gl_account'))->groupBy('account_code')->selectRaw('account_code, sum(debit) - sum(credit) as net')->pluck('net', 'account_code');

        return array_map(fn ($r) => $r + ['balance' => (string) Fixed::units((int) ($sums[$r['gl_account']] ?? 0))] + ($r['kind'] === 'cash' ? ['by_branch' => $byBranch] : []), $rows);
    }
}
