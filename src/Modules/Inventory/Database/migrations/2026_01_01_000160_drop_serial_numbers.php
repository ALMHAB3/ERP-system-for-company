<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Serial-number tracking was removed (it had no use for this business). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_serials');
        if (Schema::hasColumn('products', 'tracks_serials')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('tracks_serials'));
        }
    }

    public function down(): void {}
};
