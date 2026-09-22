<?php

use App\Jobs\ProcessHealthCheck;
use App\Jobs\ProcessUptimeCheck;
use App\Models\Site;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Site::whereHas('connections', fn ($q) => $q->where('status', 'active')->whereNull('revoked_at'))
        ->chunkById(100, function ($sites) {
            foreach ($sites as $site) {
                ProcessHealthCheck::dispatch($site->id);
                ProcessUptimeCheck::dispatch($site->id);
            }
        });
})->everyMinute()->name('monitoring.health-and-uptime');
