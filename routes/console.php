<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sms:weekly-reminder')->weekly()->sundays()->at('08:00');
Schedule::command('sms:process-scheduled')->everyMinute();
