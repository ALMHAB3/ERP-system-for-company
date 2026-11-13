<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Application\AuditLogger;
use Modules\Shared\Domain\Fixed;

/** Exchange rates to the base currency (Libyan dinar by default). One rate per currency per day, never overwritten. */
final class ExchangeRates
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLogger $audit) {}

    public function base(): string
    {
        return strtoupper((string) config('erp.base_currency'));
    }

    /** @return list<string> */
    public function activeCodes(): array
    {
        return $this->db->table('currencies')->where('active', true)->orderBy('code')->pluck('code')->all();
    }

    /** Rate valid on $date: the latest one entered on or before it. Base currency is always 1. */
    public function rateFor(string $currency, ?DateTimeImmutable $date = null): Fixed
    {
        $currency = strtoupper($currency);
        if ($currency === $this->base()) {
            return Fixed::of(1);
        }
        $rate = $this->db->table('exchange_rates')->where('currency', $currency)
            ->where('rate_date', '<=', ($date ?? CarbonImmutable::now())->format('Y-m-d'))->orderByDesc('rate_date')->value('rate');
        if ($rate === null) {
            throw new DomainException("No exchange rate for {$currency} on or before ".($date ?? CarbonImmutable::now())->format('Y-m-d').'. Enter it under Accounting > Exchange rates.');
        }

        return Fixed::units((int) $rate);
    }

    /** Uses the explicit rate when given (validated against the currency), otherwise the stored one. */
    public function resolve(string $currency, ?string $explicit, ?DateTimeImmutable $date = null): Fixed
    {
        $currency = strtoupper($currency);
        if (! in_array($currency, $this->activeCodes(), true)) {
            throw new DomainException("Currency {$currency} is not enabled.");
        }
        if ($currency === $this->base()) {
            if ($explicit !== null && ! Fixed::parse($explicit)->equals(Fixed::of(1))) {
                throw new DomainException('The base currency always has rate 1.');
            }

            return Fixed::of(1);
        }

        return $explicit !== null && $explicit !== '' ? Fixed::parse($explicit) : $this->rateFor($currency, $date);
    }

    public function set(string $currency, Fixed $rate, DateTimeImmutable $date, ?int $userId): void
    {
        $currency = strtoupper($currency);
        if ($currency === $this->base()) {
            throw new DomainException('The base currency has no rate.');
        }
        if (! $rate->isPositive()) {
            throw new DomainException('The rate must be greater than zero.');
        }
        if (! $this->db->table('currencies')->where('code', $currency)->where('active', true)->exists()) {
            throw new DomainException("Currency {$currency} is not enabled.");
        }
        if ($this->db->table('exchange_rates')->where('currency', $currency)->where('rate_date', $date->format('Y-m-d'))->exists()) {
            throw new DomainException("A {$currency} rate already exists for {$date->format('Y-m-d')}; enter a new one for another date.");
        }

        $this->db->transaction(function () use ($currency, $rate, $date, $userId) {
            $this->db->table('exchange_rates')->insert(['currency' => $currency, 'rate_date' => $date->format('Y-m-d'), 'rate' => $rate->units, 'entered_by' => $userId, 'created_at' => now()]);
            $this->audit->record('exchange_rate.set', 'currency', $currency, ['rate' => (string) $rate, 'date' => $date->format('Y-m-d')]);
        });
    }

    /** @return array<string, array{rate: ?string, date: ?string}> latest rate per active currency */
    public function latest(): array
    {
        $out = [];
        foreach ($this->activeCodes() as $code) {
            if ($code === $this->base()) {
                $out[$code] = ['rate' => '1.0000', 'date' => null];

                continue;
            }
            $row = $this->db->table('exchange_rates')->where('currency', $code)->orderByDesc('rate_date')->first(['rate', 'rate_date']);
            $out[$code] = ['rate' => $row ? (string) Fixed::units((int) $row->rate) : null, 'date' => $row->rate_date ?? null];
        }

        return $out;
    }
}
