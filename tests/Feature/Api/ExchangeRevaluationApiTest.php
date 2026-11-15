<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\ExchangeRates;
use Modules\Finance\Application\JournalService;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\JournalRepository;
use Modules\Procurement\Application\PayablesService;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Shared\Domain\Fixed;

/** Open foreign balances are restated at the closing rate and the adjustment is reversed the next day. */
final class ExchangeRevaluationApiTest extends ApiTestCase
{
    private function day(int $ago): string
    {
        return CarbonImmutable::today()->subDays($ago)->format('Y-m-d');
    }

    private function net(string $account, ?string $asOf = null): string
    {
        foreach (app(JournalRepository::class)->trialBalance($asOf === null ? null : new DateTimeImmutable($asOf)) as $r) {
            if ($r['account_code'] === $account) {
                return (string) Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']));
            }
        }

        return '0.0000';
    }

    public function test_open_payables_and_foreign_bank_balances_are_revalued_and_reversed(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $rates = app(ExchangeRates::class);
        $rates->set('USD', Fixed::parse('5'), new DateTimeImmutable($this->day(30)), null);

        // 1 000 USD owed to a supplier, booked at 5.00 = 5 000.
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Shenzhen Ltd', 'currency' => 'USD', 'terms_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(PayablesService::class)->recordInvoice($supplier, 'INV-1', new DateTimeImmutable($this->day(20)), 'USD', Fixed::parse('5'), Fixed::parse('1000'), Fixed::zero(), InvoiceKind::Expense, null, '5100');
        // 200 USD in a dollar bank account, carried at 1 000.
        $bank = $this->as($finance)->postJson('/api/finance/bank-accounts', ['name' => 'USD account', 'currency' => 'USD'])->assertCreated()->json('id');
        $gl = (string) DB::table('bank_accounts')->where('id', $bank)->value('gl_account');
        app(JournalService::class)->post(new JournalEntryData('capital paid in', [
            JournalLineData::debit($gl, Fixed::parse('1000'), null, 'USD', Fixed::parse('200')), JournalLineData::credit('1200', Fixed::parse('1000')),
        ], 'test', 'cap', 'test', new DateTimeImmutable($this->day(20).' 09:00:00')));

        $asOf = $this->day(2);
        $rates->set('USD', Fixed::parse('5.5'), new DateTimeImmutable($asOf), null);

        $preview = $this->as($finance)->getJson('/api/finance/fx-revaluations/preview?as_of='.$asOf)->assertOk()->json();
        $this->assertSame('400.0000', $preview['net']);                                   // 500 loss on the payable - 100 gain on the bank
        $this->assertSame(0, DB::table('fx_revaluations')->count());                      // a preview posts nothing

        $this->as($this->userWith(['accountant']))->postJson('/api/finance/fx-revaluations', ['as_of' => $asOf])->assertForbidden();
        $run = $this->as($finance)->postJson('/api/finance/fx-revaluations', ['as_of' => $asOf])->assertCreated()->json();
        $this->assertSame('400.0000', $run['net']);
        $this->assertCount(2, $run['lines']);

        // On the closing day the balances stand at the closing rate ...
        $this->assertSame('-500.0000', $this->net('2000', $asOf));    // the adjustment alone (the invoice itself is posted when it is entered: today)
        $this->assertSame('1100.0000', $this->net($gl, $asOf));
        $this->assertSame('400.0000', $this->net('5210', $asOf));
        // ... and the next day the adjustment is gone, so the realised difference is measured against the booked rate.
        $this->assertSame('-5000.0000', $this->net('2000'));
        $this->assertSame('1000.0000', $this->net($gl));
        $this->assertSame('0.0000', $this->net('5210'));
        $this->assertSame(2000000, (int) DB::table('journal_lines')->where('account_code', $gl)->sum('amount_currency'));   // still exactly 200 USD

        $this->as($finance)->postJson('/api/finance/fx-revaluations', ['as_of' => $asOf])->assertUnprocessable();                    // once per date
        $this->as($finance)->postJson('/api/finance/fx-revaluations', ['as_of' => $this->day(5)])->assertUnprocessable();            // never backwards
        $this->as($finance)->postJson('/api/finance/fx-revaluations', ['as_of' => CarbonImmutable::today()->addDay()->format('Y-m-d')])->assertUnprocessable();
        $this->as($finance)->getJson('/api/finance/fx-revaluations')->assertOk()->assertJsonPath('data.0.as_of', $asOf)->assertJsonPath('data.0.reverse_on', $this->day(1));

        // Paying the invoice at 5.50 now books the whole 500 as a realised loss.
        app(PayablesService::class)->pay($supplier, new DateTimeImmutable($this->day(0)), 'USD', Fixed::parse('5.5'), 'transfer', 'TT-1', [(int) DB::table('supplier_invoices')->value('id') => Fixed::parse('1000')], '1100');
        $this->assertSame('0.0000', $this->net('2000'));
        $this->assertSame('500.0000', $this->net('5200'));
    }

    public function test_nothing_to_revalue_is_refused(): void
    {
        $this->as($this->userWith(['finance_manager']))->postJson('/api/finance/fx-revaluations', ['as_of' => $this->day(1)])->assertUnprocessable();
    }
}
