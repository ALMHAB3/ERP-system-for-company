<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('url', 500);
            $t->string('secret', 80);              // signs every delivery (HMAC-SHA256)
            $t->json('events');                    // e.g. ["invoice.issued","payment.received"]
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints');
            $t->string('event', 40);
            $t->text('payload');
            $t->string('status', 10)->default('pending');   // pending | delivered | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('last_code')->nullable();
            $t->string('last_error', 250)->nullable();
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
