<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Webhook delivery isn't guaranteed, so poll for generations that never
// settled. Also clears submissions that got stuck before Pixelforge answered.
Schedule::command('generations:poll-stale')->everyMinute();
