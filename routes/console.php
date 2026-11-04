<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

if (config('services.zoho_items_pull.sales_schedule_enabled')) {
    Schedule::command('zoho:pull-sales')->hourlyAt(40)->withoutOverlapping(240);
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
