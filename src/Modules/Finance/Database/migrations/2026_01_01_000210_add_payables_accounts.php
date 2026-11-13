<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            ['code' => '1400', 'name' => 'VAT receivable (input VAT)', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability'],
            ['code' => '5200', 'name' => 'Exchange differences', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'Purchase price variance', 'type' => 'expense'],
            ['code' => '5400', 'name' => 'General expenses', 'type' => 'expense'],
        ] as $account) {
            if (! DB::table('accounts')->where('code', $account['code'])->exists()) {
                DB::table('accounts')->insert($account + ['active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // accounts referenced by journal lines cannot be removed safely
    }
};
