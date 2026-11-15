<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('campaigns:spawn')->daily()->timezone(config('app.timezone'))->withoutOverlapping();
Schedule::command('campaigns:close-due')->daily()->timezone(config('app.timezone'))->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
