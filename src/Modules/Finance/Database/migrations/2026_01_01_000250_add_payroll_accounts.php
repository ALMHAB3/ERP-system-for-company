<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['1350', 'Employee advances', 'asset'], ['2300', 'Salaries payable', 'liability'], ['2310', 'Social security payable', 'liability'],
            ['5500', 'Salaries expense', 'expense'], ['5510', 'Employer social security expense', 'expense'],
        ] as [$code, $name, $type]) {
            if (! DB::table('accounts')->where('code', $code)->exists()) {
                DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
