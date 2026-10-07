<?php

namespace App\Providers;

use App\Contracts\ConnectorCredentialResolver;
use App\Events\NotificationSourceEvent;
use App\Listeners\DeliverNotification;
use App\Models\ApprovalRequest;
use App\Models\AutomationRun;
use App\Models\AvailableUpdate;
use App\Models\Incident;
use App\Models\Operation;
use App\Models\OperationResult;
use App\Observers\ApprovalRequestObserver;
use App\Observers\AutomationRunObserver;
use App\Observers\AvailableUpdateObserver;
use App\Observers\IncidentObserver;
use App\Observers\OperationObserver;
use App\Observers\OperationResultObserver;
use App\Services\DatabaseConnectorCredentialResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConnectorCredentialResolver::class, DatabaseConnectorCredentialResolver::class);
    }

    public function boot(): void
    {
        Operation::observe(OperationObserver::class);
        Incident::observe(IncidentObserver::class);
        AvailableUpdate::observe(AvailableUpdateObserver::class);
        OperationResult::observe(OperationResultObserver::class);
        ApprovalRequest::observe(ApprovalRequestObserver::class);
        AutomationRun::observe(AutomationRunObserver::class);
        Event::listen(NotificationSourceEvent::class, DeliverNotification::class);
    }
}
