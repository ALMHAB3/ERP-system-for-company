<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Audit\Application\AuditVerifier;

final class AuditTrailTest extends ErpTestCase
{
    public function test_chain_verifies_and_entries_cannot_be_edited_or_deleted(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('test.one', 'thing', 1, ['a' => 1]);
        $audit->record('test.two', 'thing', 2, ['b' => 'نص عربي']);
        $this->assertTrue(app(AuditVerifier::class)->verify()['ok']);

        foreach ([fn () => DB::table('audit_logs')->update(['action' => 'x']), fn () => DB::table('audit_logs')->delete()] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('audit rows must be immutable');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_a_forged_row_breaks_the_chain(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('test.one');
        DB::table('audit_logs')->insert([   // inserted behind the logger's back
            'occurred_at' => now()->format('Y-m-d H:i:s'), 'action' => 'stock.adjustment_in', 'data' => '{}',
            'prev_hash' => str_repeat('f', 64), 'hash' => str_repeat('e', 64),
        ]);

        $result = app(AuditVerifier::class)->verify();
        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['checked']);
        $this->artisan('audit:verify')->assertFailed();
    }

    public function test_a_failed_operation_leaves_no_audit_entry(): void
    {
        try {
            DB::transaction(function () {
                app(AuditLogger::class)->record('will.rollback');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
