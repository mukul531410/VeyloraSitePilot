<?php

namespace App\Providers;

use App\Models\Operation;
use App\Observers\OperationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Operation::observe(OperationObserver::class);
    }
}
