<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small switches about the installation itself, e.g. "demo data was loaded" (drives a warning banner in the UI).
        Schema::create('system_flags', function (Blueprint $t) {
            $t->string('name', 60)->primary();
            $t->string('value', 255);
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_flags');
    }
};
