<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([['3000', 'Owner capital', 'equity'], ['3100', 'Retained earnings', 'equity']] as [$code, $name, $type]) {
            if (! DB::table('accounts')->where('code', $code)->exists()) {
                DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
