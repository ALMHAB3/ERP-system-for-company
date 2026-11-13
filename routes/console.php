<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly proof that stored stock balances equal the immutable ledger (non-zero exit on drift).
Schedule::command('inventory:reconcile')->dailyAt('02:00')->onOneServer();
Schedule::command('audit:verify')->dailyAt('02:15')->onOneServer();
