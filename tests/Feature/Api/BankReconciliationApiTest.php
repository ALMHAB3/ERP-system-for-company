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
use Modules\Shared\Domain\Fixed;

/** Bank statements are matched line by line to the ledger; what the ledger lacks is booked from the statement. */
final class BankReconciliationApiTest extends ApiTestCase
{
    private object $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = $this->userWith(['finance_manager']);
    }

    private function day(int $ago): string
    {
        return CarbonImmutable::today()->subDays($ago)->format('Y-m-d');
    }

    private function bank(string $currency = 'LYD'): array
    {
        $id = $this->as($this->finance)->postJson('/api/finance/bank-accounts', ['name' => 'Jumhouria '.$currency, 'currency' => $currency])->assertCreated()->json('id');

        return [$id, (string) DB::table('bank_accounts')->where('id', $id)->value('gl_account')];
    }

    /** Posts a movement on the bank's ledger account against $other: positive = money in. */
    private function movement(string $gl, string $amount, string $other, string $date, string $ref, ?string $currency = null, ?string $foreign = null): void
    {
        $value = Fixed::parse(ltrim($amount, '-'));
        $in = ! str_starts_with($amount, '-');
        $bankLine = $in ? JournalLineData::debit($gl, $value, $ref) : JournalLineData::credit($gl, $value, $ref);
        if ($currency !== null) {
            $bankLine = $bankLine->inCurrency($currency, Fixed::parse($foreign));
        }
        $otherLine = $in ? JournalLineData::credit($other, $value) : JournalLineData::debit($other, $value);
        app(JournalService::class)->post(new JournalEntryData($ref, [$bankLine, $otherLine], 'test', $ref, 'test', new DateTimeImmutable($date.' 10:00:00')));
    }

    private function balance(string $account): string
    {
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            if ($r['account_code'] === $account) {
                return (string) Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']));
            }
        }

        return '0.0000';
    }

    public function test_statement_is_matched_booked_and_reconciled(): void
    {
        [$bank, $gl] = $this->bank();
        $this->movement($gl, '500', '1200', $this->day(10), 'RCPT-1');     // customer transfer received
        $this->movement($gl, '-200', '2000', $this->day(8), 'PAY-1');      // supplier paid
        $this->movement($gl, '-50', '2000', $this->day(3), 'CHQ-9');       // cheque the bank has not cashed yet

        $statement = ['bank_account_id' => $bank, 'reference' => 'ST-01', 'date' => $this->day(2), 'opening_balance' => '0', 'closing_balance' => '285', 'lines' => [
            ['date' => $this->day(9), 'description' => 'Incoming transfer', 'amount' => '500'],
            ['date' => $this->day(7), 'description' => 'Outgoing transfer', 'amount' => '-200'],
            ['date' => $this->day(5), 'description' => 'Monthly fee', 'amount' => '-15'],
        ]];
        $this->as($this->userWith(['accountant']))->postJson('/api/finance/bank-statements', $statement)->assertForbidden();
        $this->as($this->finance)->postJson('/api/finance/bank-statements', ['closing_balance' => '300'] + $statement)->assertUnprocessable();   // does not add up
        $id = $this->as($this->finance)->postJson('/api/finance/bank-statements', $statement)->assertCreated()->json('id');
        $this->as($this->finance)->postJson('/api/finance/bank-statements', $statement)->assertUnprocessable();                                   // same reference twice

        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id}/reconcile")->assertUnprocessable();   // nothing matched yet
        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id}/auto-match")->assertOk()->assertJsonPath('matched', 2);

        $view = $this->as($this->finance)->getJson("/api/finance/bank-statements/{$id}")->assertOk()->json();
        $fee = collect($view['lines'])->firstWhere('description', 'Monthly fee');
        $this->assertFalse($fee['matched']);
        $this->assertSame('250.0000', $view['summary']['ledger_balance']);                 // 500 - 200 - 50
        $this->assertSame('-50.0000', $view['summary']['outstanding_ledger_total']);       // the uncashed cheque
        $this->assertSame('-15.0000', $view['summary']['unbooked_statement_total']);
        $this->assertSame('0.0000', $view['summary']['difference']);

        $this->as($this->finance)->getJson("/api/finance/bank-statement-lines/{$fee['id']}/candidates")->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$fee['id']}/book", ['account' => $gl])->assertUnprocessable();    // not against itself
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$fee['id']}/book", ['account' => '5600'])->assertNoContent();
        $this->assertSame('15.0000', $this->balance('5600'));
        $this->assertSame('235.0000', $this->balance($gl));

        $view = $this->as($this->finance)->getJson("/api/finance/bank-statements/{$id}")->json();
        $this->assertSame('0.0000', $view['summary']['difference']);
        $this->assertSame('285.0000', $view['summary']['adjusted_ledger_balance']);
        $this->assertCount(1, $view['outstanding_ledger_lines']);

        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id}/reconcile")->assertNoContent();
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$fee['id']}/unmatch")->assertUnprocessable();                    // locked
        $this->as($this->finance)->getJson('/api/finance/bank-statements?bank_account_id='.$bank)->assertOk()->assertJsonPath('data.0.status', 'reconciled');

        // The next statement must continue from 285 and can pick up the cheque once the bank cashes it.
        $next = ['bank_account_id' => $bank, 'reference' => 'ST-02', 'date' => $this->day(0), 'opening_balance' => '0', 'closing_balance' => '-50', 'lines' => [['date' => $this->day(1), 'description' => 'Cheque 9', 'amount' => '-50']]];
        $this->as($this->finance)->postJson('/api/finance/bank-statements', $next)->assertUnprocessable();
        $id2 = $this->as($this->finance)->postJson('/api/finance/bank-statements', ['opening_balance' => '285', 'closing_balance' => '235'] + $next)->assertCreated()->json('id');
        $line = $this->as($this->finance)->getJson("/api/finance/bank-statements/{$id2}")->json('lines.0.id');
        $candidate = $this->as($this->finance)->getJson("/api/finance/bank-statement-lines/{$line}/candidates")->assertJsonCount(1, 'data')->json('data.0.journal_line_id');
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$line}/match", ['journal_line_id' => $candidate + 1])->assertUnprocessable();   // not a movement of this bank
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$line}/match", ['journal_line_id' => $candidate])->assertNoContent();
        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id2}/reconcile")->assertNoContent();
    }

    public function test_a_foreign_currency_bank_is_reconciled_in_its_own_currency(): void
    {
        app(ExchangeRates::class)->set('USD', Fixed::parse('5'), new DateTimeImmutable($this->day(30)), null);
        [$bank, $gl] = $this->bank('USD');
        $this->movement($gl, '1000', '1200', $this->day(10), 'USD-IN', 'USD', '200');   // 200 USD booked at 5.00
        $this->movement($gl, '-250', '2000', $this->day(9), 'LYD-OUT');                 // no currency given: derived at the stored rate
        $this->assertSame(-500000, (int) DB::table('journal_lines')->where('account_code', $gl)->where('credit', '>', 0)->value('amount_currency'));   // 250 / 5 = 50 USD

        $id = $this->as($this->finance)->postJson('/api/finance/bank-statements', ['bank_account_id' => $bank, 'reference' => 'USD-01', 'date' => $this->day(1), 'opening_balance' => '0', 'closing_balance' => '148', 'lines' => [
            ['date' => $this->day(10), 'description' => 'Deposit', 'amount' => '200'], ['date' => $this->day(9), 'description' => 'Transfer', 'amount' => '-50'], ['date' => $this->day(4), 'description' => 'Swift fee', 'amount' => '-2'],
        ]])->assertCreated()->json('id');
        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id}/auto-match")->assertJsonPath('matched', 2);
        $fee = collect($this->as($this->finance)->getJson("/api/finance/bank-statements/{$id}")->json('lines'))->firstWhere('matched', false)['id'];
        $this->as($this->finance)->postJson("/api/finance/bank-statement-lines/{$fee}/book", ['account' => '5600'])->assertNoContent();
        $this->assertSame('10.0000', $this->balance('5600'));                           // 2 USD at 5.00
        $view = $this->as($this->finance)->getJson("/api/finance/bank-statements/{$id}")->json('summary');
        $this->assertSame('148.0000', $view['ledger_balance']);                         // in USD
        $this->assertSame('0.0000', $view['difference']);
        $this->as($this->finance)->postJson("/api/finance/bank-statements/{$id}/reconcile")->assertNoContent();
    }

    public function test_account_ledger_shows_running_balance(): void
    {
        [, $gl] = $this->bank();
        $this->movement($gl, '500', '1200', $this->day(40), 'OLD');
        $this->movement($gl, '300', '1200', $this->day(5), 'NEW-1');
        $this->movement($gl, '-120', '2000', $this->day(4), 'NEW-2');
        $r = $this->as($this->finance)->getJson("/api/finance/ledger?account={$gl}&from={$this->day(10)}&to={$this->day(0)}")->assertOk()->json();
        $this->assertSame('500.0000', $r['opening']);
        $this->assertSame(['800.0000', '680.0000'], array_column($r['lines'], 'balance'));
        $this->assertSame('680.0000', $r['closing']);
        $this->as($this->finance)->getJson("/api/finance/ledger?account=9999&from={$this->day(10)}&to={$this->day(0)}")->assertNotFound();
        $this->as($this->finance)->getJson('/api/finance/trial-balance?as_of='.$this->day(20))->assertOk();
        $this->assertSame('500.0000', collect($this->as($this->finance)->getJson('/api/finance/trial-balance?as_of='.$this->day(20))->json('data'))->firstWhere('account_code', $gl)['debit']);
    }
}
