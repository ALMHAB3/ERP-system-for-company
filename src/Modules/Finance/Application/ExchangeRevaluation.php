<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Domain\Events\ExchangeRevalued;
use Modules\Finance\Domain\Events\RevaluationAdjustment;
use Modules\Finance\Domain\ForeignBalanceSource;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Period-end valuation of exchange differences (unrealised). Every open balance in a foreign currency is
 * restated at the closing rate; the adjustment is posted on the closing day and reversed the day after.
 * Realised differences are booked separately, when the payment is made.
 */
final class ExchangeRevaluation
{
    /** @param iterable<ForeignBalanceSource> $sources */
    public function __construct(
        private readonly iterable $sources,
        private readonly ExchangeRates $rates,
        private readonly ConnectionInterface $db,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a revaluation at $asOf would post, without posting it.
     *
     * @return array{as_of:string,net:string,lines:list<array<string,mixed>>}
     */
    public function preview(DateTimeImmutable $asOf): array
    {
        [$lines, $net] = $this->compute($asOf);

        return ['as_of' => $asOf->format('Y-m-d'), 'net' => (string) Fixed::units($net), 'lines' => array_map($this->present(...), $lines)];
    }

    /** @return array{id:int,as_of:string,net:string,lines:list<array<string,mixed>>} */
    public function run(DateTimeImmutable $asOf, ?int $userId): array
    {
        if ($asOf->format('Y-m-d') > CarbonImmutable::today()->format('Y-m-d')) {
            throw new DomainException('Balances cannot be revalued at a future date.');
        }

        return $this->tx->run(function () use ($asOf, $userId): array {
            $last = $this->db->table('fx_revaluations')->max('as_of');
            if ($last !== null && substr((string) $last, 0, 10) >= $asOf->format('Y-m-d')) {
                throw new DomainException('Balances were already revalued at '.substr((string) $last, 0, 10).'; choose a later date.');
            }
            [$lines, $net] = $this->compute($asOf);
            if (array_filter($lines, static fn (array $l) => $l['difference'] !== 0) === []) {
                throw new DomainException('There is no open foreign balance whose value changed at this rate.');
            }
            $reverseOn = $asOf->modify('+1 day');
            $id = $this->db->table('fx_revaluations')->insertGetId([
                'as_of' => $asOf->format('Y-m-d'), 'reverse_on' => $reverseOn->format('Y-m-d'), 'net' => $net, 'created_by' => $userId, 'created_at' => now(),
            ]);
            $this->db->table('fx_revaluation_lines')->insert(array_map(static fn (array $l) => array_diff_key($l, ['foreign_account' => 1]) + ['fx_revaluation_id' => $id], $lines));

            // One ledger adjustment per account and currency; the per-document detail stays in fx_revaluation_lines.
            $grouped = [];
            foreach ($lines as $l) {
                $key = $l['account_code'].'|'.$l['currency'];
                $grouped[$key] ??= ['account' => $l['account_code'], 'currency' => $l['currency'], 'foreign' => $l['foreign_account'], 'difference' => 0];
                $grouped[$key]['difference'] += $l['difference'];
            }
            $adjustments = array_map(static fn (array $g) => new RevaluationAdjustment(
                $g['account'], Fixed::units($g['difference']), "revaluation {$g['currency']} at ".$asOf->format('Y-m-d'), $g['currency'], $g['foreign'],
            ), array_values($grouped));

            $this->events->dispatch(new ExchangeRevalued($id, $asOf, $reverseOn, $adjustments));
            $this->audit->record('fx.revalued', 'fx_revaluation', $id, ['as_of' => $asOf->format('Y-m-d'), 'net' => (string) Fixed::units($net)], $userId);

            return ['id' => $id, 'as_of' => $asOf->format('Y-m-d'), 'net' => (string) Fixed::units($net), 'lines' => array_map($this->present(...), $lines)];
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        return $this->db->table('fx_revaluations')->orderByDesc('as_of')->limit(100)->get()
            ->map(static fn ($r) => ['id' => (int) $r->id, 'as_of' => substr((string) $r->as_of, 0, 10), 'reverse_on' => substr((string) $r->reverse_on, 0, 10), 'net' => (string) Fixed::units((int) $r->net)])->all();
    }

    /** @return array{0: list<array<string,mixed>>, 1: int} detail rows (integer units) and the net loss (+) or gain (-) */
    private function compute(DateTimeImmutable $asOf): array
    {
        $base = $this->rates->base();
        $lines = [];
        $net = 0;
        foreach ($this->sources as $source) {
            foreach ($source->openBalances($base, $asOf) as $b) {
                $rate = $this->rates->rateFor($b->currency, $asOf);
                $revalued = $b->foreign->mul($rate);
                $difference = $revalued->sub($b->carryingBase);
                $lines[] = [
                    'account_code' => $b->accountCode, 'subject' => mb_substr($b->subject, 0, 160), 'currency' => $b->currency, 'rate' => $rate->units,
                    'foreign_balance' => $b->foreign->units, 'carrying_base' => $b->carryingBase->units, 'revalued_base' => $revalued->units, 'difference' => $difference->units,
                    'foreign_account' => $b->foreignAccount,
                ];
                // A higher debit balance is a gain, so the loss is the negative of the change.
                $net -= $difference->units;
            }
        }

        return [$lines, $net];
    }

    /** @param array<string,mixed> $l @return array<string,mixed> */
    private function present(array $l): array
    {
        return [
            'account_code' => $l['account_code'], 'subject' => $l['subject'], 'currency' => $l['currency'], 'rate' => (string) Fixed::units($l['rate']),
            'foreign_balance' => (string) Fixed::units($l['foreign_balance']), 'carrying_base' => (string) Fixed::units($l['carrying_base']),
            'revalued_base' => (string) Fixed::units($l['revalued_base']), 'difference' => (string) Fixed::units($l['difference']),
        ];
    }
}
