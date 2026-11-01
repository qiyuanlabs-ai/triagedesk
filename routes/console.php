<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The public demo starts every day from the same seed data (the Docker deploy uses a host cron instead).
Schedule::command('triagedesk:reset-demo')
    ->dailyAt('00:00')
    ->when(fn () => config('triagedesk.demo.enabled'));
