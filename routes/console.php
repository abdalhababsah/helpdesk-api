<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Outcomes that depend on time passing rather than on anything anyone does.
Schedule::command('assistant:settle-sessions')->everyFifteenMinutes();
