<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly proof that stored stock balances equal the immutable ledger (non-zero exit on drift).
Illuminate\Support\Facades\Schedule::command('inventory:reconcile')->dailyAt('02:00')->onOneServer();
