<?php

namespace App\Jobs;

use App\Models\HealthCheck;
use App\Models\Incident;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteMetric;
use App\Models\UptimeCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessHealthCheck implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $timeout = 60;
    public int $tries = 3;

    private string $correlationId;
    private Carbon $now;

    private static array $incidentTypeMap = [
        HealthCheck::CHECK_TYPE_HEARTBEAT => Incident::TYPE_HEARTBEAT_LOSS,
        HealthCheck::CHECK_TYPE_UPTIME => Incident::TYPE_SITE_UNREACHABLE,
        HealthCheck::CHECK_TYPE_REACHABILITY => Incident::TYPE_SITE_UNREACHABLE,
        HealthCheck::CHECK_TYPE_HTTP_RESPONSE => Incident::TYPE_HEALTH_DEGRADED,
        HealthCheck::CHECK_TYPE_WORDPRESS_STATE => Incident::TYPE_HEALTH_DEGRADED,
        HealthCheck::CHECK_TYPE_SSL => Incident::TYPE_SSL_EXPIRING,
        HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS => Incident::TYPE_CRITICAL_FINDINGS,
    ];

    public function __construct(
        public string $siteId,
    ) {
        $this->correlationId = Str::uuid()->toString();
    }

    public function uniqueId(): string
    {
        return 'health-check:' . $this->siteId;
    }

    public function handle(): void
    {
        $this->now = now();

        $site = Site::findOrFail($this->siteId);

        DB::transaction(fn () => $this->runHealthCheck($site));
    }

    private function runHealthCheck(Site $site): void
    {
        $connection = $this->getLatestActiveConnection($site);

        $signals = $this->collectSignals($site, $connection);

        $checks = $this->evaluateChecks($signals);

        $this->persistChecks($site, $checks);

        $healthState = $this->computeHealthState($checks);

        SiteMetric::create([
            'site_id' => $site->id,
            'metric_type' => SiteMetric::METRIC_TYPE_HEALTH_STATE,
            'value' => null,
            'unit' => $healthState,
            'observed_at' => $this->now,
        ]);

        $this->manageIncidents($site, $checks);
    }

    private function getLatestActiveConnection(Site $site): ?SiteConnection
    {
        return $site->connections()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    private function collectSignals(Site $site, ?SiteConnection $connection): array
    {
        $heartbeat = $connection
            ? $connection->heartbeats()->latest('reported_at')->first()
            : null;

        $uptimeCheck = $site->uptimeChecks()
            ->latest('checked_at')
            ->first();

        $latestHeartbeatTime = $connection ? $connection->last_seen_at : null;

        $heartbeatAgeMinutes = null;
        if ($latestHeartbeatTime) {
            $heartbeatAgeMinutes = $this->now->diffInMinutes($latestHeartbeatTime, true);
        }

        $uptimeStatus = $uptimeCheck ? $uptimeCheck->status : null;
        $httpStatus = $uptimeCheck ? $uptimeCheck->http_status : null;
        $uptimeFresh = $uptimeCheck
            ? $this->now->diffInMinutes($uptimeCheck->checked_at, true) < 15
            : false;

        return [
            'connection' => $connection,
            'connection_status' => $connection ? $connection->status : null,
            'heartbeat_age_minutes' => $heartbeatAgeMinutes,
            'wordpress_version' => $heartbeat ? $heartbeat->wordpress_version : null,
            'uptime_status' => $uptimeStatus,
            'http_status' => $httpStatus,
            'uptime_check_fresh' => $uptimeFresh,
        ];
    }

    private function evaluateChecks(array $signals): array
    {
        $checks = [];

        $checks['heartbeat'] = $this->evaluateHeartbeat($signals);
        $checks['uptime'] = $this->evaluateUptime($signals);
        $checks['http_response'] = $this->evaluateHttpResponse($signals);
        $checks['wordpress_state'] = $this->evaluateWordPressState($signals);
        $checks['reachability'] = $this->evaluateReachability($signals);
        $checks['critical_findings'] = $this->evaluateCriticalFindings($signals);

        return $checks;
    }

    private function evaluateHeartbeat(array $signals): array
    {
        $age = $signals['heartbeat_age_minutes'];
        $connectionStatus = $signals['connection_status'];
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_HEARTBEAT];

        if ($connectionStatus !== 'active') {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HEARTBEAT, HealthCheck::STATUS_FAIL, ['reason' => 'no_active_connection'], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        if ($age === null) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HEARTBEAT, HealthCheck::STATUS_FAIL, ['reason' => 'no_heartbeat_data'], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        if ($age >= HealthCheck::THRESHOLD_CRITICAL_MINUTES) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HEARTBEAT, HealthCheck::STATUS_FAIL, ['heartbeat_age_minutes' => (int) $age], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        if ($age >= HealthCheck::THRESHOLD_WARNING_MINUTES) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HEARTBEAT, HealthCheck::STATUS_WARN, ['heartbeat_age_minutes' => (int) $age], $incidentType, null);
        }

        return $this->checkResult(HealthCheck::CHECK_TYPE_HEARTBEAT, HealthCheck::STATUS_PASS, ['heartbeat_age_minutes' => (int) $age], $incidentType, null);
    }

    private function evaluateUptime(array $signals): array
    {
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_UPTIME];
        $uptimeStatus = $signals['uptime_status'];

        if (! $uptimeStatus) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_UPTIME, HealthCheck::STATUS_FAIL, ['reason' => 'no_uptime_data'], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        if ($uptimeStatus === UptimeCheck::STATUS_DOWN) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_UPTIME, HealthCheck::STATUS_FAIL, ['uptime_status' => $uptimeStatus], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        return $this->checkResult(HealthCheck::CHECK_TYPE_UPTIME, HealthCheck::STATUS_PASS, ['uptime_status' => $uptimeStatus], $incidentType, null);
    }

    private function evaluateHttpResponse(array $signals): array
    {
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_HTTP_RESPONSE];
        $httpStatus = $signals['http_status'];

        if ($httpStatus === null) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HTTP_RESPONSE, HealthCheck::STATUS_WARN, ['reason' => 'no_http_data'], $incidentType, null);
        }

        if ($httpStatus >= 500) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HTTP_RESPONSE, HealthCheck::STATUS_FAIL, ['http_status' => $httpStatus], $incidentType, Incident::SEVERITY_HIGH);
        }

        if ($httpStatus >= 400) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_HTTP_RESPONSE, HealthCheck::STATUS_WARN, ['http_status' => $httpStatus], $incidentType, null);
        }

        return $this->checkResult(HealthCheck::CHECK_TYPE_HTTP_RESPONSE, HealthCheck::STATUS_PASS, ['http_status' => $httpStatus], $incidentType, null);
    }

    private function evaluateWordPressState(array $signals): array
    {
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_WORDPRESS_STATE];

        if ($signals['wordpress_version'] === null) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_WORDPRESS_STATE, HealthCheck::STATUS_WARN, ['reason' => 'no_wordpress_version'], $incidentType, null);
        }

        return $this->checkResult(HealthCheck::CHECK_TYPE_WORDPRESS_STATE, HealthCheck::STATUS_PASS, ['wordpress_version' => $signals['wordpress_version']], $incidentType, null);
    }

    private function evaluateReachability(array $signals): array
    {
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_REACHABILITY];

        $heartbeatOk = ($signals['connection_status'] ?? null) === 'active'
            && $signals['heartbeat_age_minutes'] !== null
            && $signals['heartbeat_age_minutes'] < HealthCheck::THRESHOLD_CRITICAL_MINUTES;

        $uptimeOk = $signals['uptime_status'] === UptimeCheck::STATUS_UP
            && $signals['uptime_check_fresh'];

        if (! $heartbeatOk && ! $uptimeOk) {
            return $this->checkResult(HealthCheck::CHECK_TYPE_REACHABILITY, HealthCheck::STATUS_FAIL, ['reason' => 'both_heartbeat_and_uptime_unhealthy'], $incidentType, Incident::SEVERITY_CRITICAL);
        }

        return $this->checkResult(HealthCheck::CHECK_TYPE_REACHABILITY, HealthCheck::STATUS_PASS, ['heartbeat_ok' => $heartbeatOk, 'uptime_ok' => $uptimeOk], $incidentType, null);
    }

    private function evaluateCriticalFindings(array $signals): array
    {
        $incidentType = self::$incidentTypeMap[HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS];

        return $this->checkResult(HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS, HealthCheck::STATUS_PASS, ['findings_count' => 0], $incidentType, null);
    }

    private function checkResult(string $checkType, string $status, array $value, ?string $incidentType, ?string $severity): array
    {
        return [
            'check_type' => $checkType,
            'status' => $status,
            'value' => $value,
            'incident_type' => $incidentType,
            'severity' => $severity,
        ];
    }

    private function persistChecks(Site $site, array $checks): void
    {
        foreach ($checks as $check) {
            HealthCheck::create([
                'site_id' => $site->id,
                'check_type' => $check['check_type'],
                'status' => $check['status'],
                'value_json' => $check['value'] ?? [],
                'checked_at' => $this->now,
            ]);
        }
    }

    private function computeHealthState(array $checks): string
    {
        $hasCriticalFail = false;
        $hasFail = false;
        $hasWarn = false;
        $hasActiveConnection = false;

        foreach ($checks as $check) {
            if ($check['check_type'] === HealthCheck::CHECK_TYPE_HEARTBEAT) {
                $hasActiveConnection = $check['status'] !== HealthCheck::STATUS_FAIL
                    || ($check['value']['reason'] ?? null) !== 'no_active_connection';
            }

            if ($check['status'] === HealthCheck::STATUS_FAIL) {
                $hasFail = true;

                $criticalTypes = [
                    HealthCheck::CHECK_TYPE_HEARTBEAT,
                    HealthCheck::CHECK_TYPE_UPTIME,
                    HealthCheck::CHECK_TYPE_REACHABILITY,
                    HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS,
                ];

                if (in_array($check['check_type'], $criticalTypes, true)) {
                    $hasCriticalFail = true;
                }
            }

            if ($check['status'] === HealthCheck::STATUS_WARN) {
                $hasWarn = true;
            }
        }

        if (! $hasActiveConnection) {
            return HealthCheck::STATE_UNKNOWN;
        }

        if ($hasCriticalFail) {
            return HealthCheck::STATE_CRITICAL;
        }

        if ($hasFail) {
            return HealthCheck::STATE_DEGRADED;
        }

        if ($hasWarn) {
            return HealthCheck::STATE_ATTENTION;
        }

        return HealthCheck::STATE_HEALTHY;
    }

    private function manageIncidents(Site $site, array $checks): void
    {
        $this->resolveRecoveredIncidents($site, $checks);
        $this->createOrUpdateIncidents($site, $checks);
    }

    private function resolveRecoveredIncidents(Site $site, array $checks): void
    {
        $resolvedTypes = [];

        foreach ($checks as $check) {
            if (
                $check['status'] === HealthCheck::STATUS_PASS
                && $check['incident_type'] !== null
            ) {
                $resolvedTypes[] = $check['incident_type'];
            }
        }

        if (empty($resolvedTypes)) {
            return;
        }

        Incident::where('site_id', $site->id)
            ->whereIn('type', $resolvedTypes)
            ->whereIn('status', Incident::OPEN_STATUSES)
            ->update([
                'status' => Incident::STATUS_RESOLVED,
                'resolved_at' => $this->now,
                'last_detected_at' => $this->now,
            ]);
    }

    private function createOrUpdateIncidents(Site $site, array $checks): void
    {
        $failingChecks = array_filter(
            $checks,
            fn ($c) => $c['status'] === HealthCheck::STATUS_FAIL && $c['incident_type'] !== null
        );

        foreach ($failingChecks as $check) {
            $incidentType = $check['incident_type'];
            $severity = $check['severity'];
            $checkType = $check['check_type'];

            $existing = Incident::where('site_id', $site->id)
                ->where('type', $incidentType)
                ->whereIn('status', Incident::OPEN_STATUSES)
                ->first();

            if ($existing) {
                $existing->update([
                    'last_detected_at' => $this->now,
                ]);

                continue;
            }

            $resolved = Incident::where('site_id', $site->id)
                ->where('type', $incidentType)
                ->where('status', Incident::STATUS_RESOLVED)
                ->latest('resolved_at')
                ->first();

            if ($resolved) {
                $resolved->update([
                    'status' => Incident::STATUS_DETECTED,
                    'first_detected_at' => $this->now,
                    'last_detected_at' => $this->now,
                    'resolved_at' => null,
                    'severity' => $severity,
                    'title' => $this->generateIncidentTitle($checkType),
                    'description' => $this->generateIncidentDescription($check),
                ]);

                continue;
            }

            Incident::create([
                'site_id' => $site->id,
                'type' => $incidentType,
                'severity' => $severity,
                'status' => Incident::STATUS_DETECTED,
                'title' => $this->generateIncidentTitle($checkType),
                'description' => $this->generateIncidentDescription($check),
                'first_detected_at' => $this->now,
                'last_detected_at' => $this->now,
            ]);
        }
    }

    private function generateIncidentTitle(string $checkType): string
    {
        $titles = [
            HealthCheck::CHECK_TYPE_HEARTBEAT => 'Connector heartbeat lost',
            HealthCheck::CHECK_TYPE_UPTIME => 'Site is unreachable',
            HealthCheck::CHECK_TYPE_REACHABILITY => 'Site reachability failure',
            HealthCheck::CHECK_TYPE_HTTP_RESPONSE => 'HTTP response degraded',
            HealthCheck::CHECK_TYPE_SSL => 'SSL certificate expiring',
            HealthCheck::CHECK_TYPE_WORDPRESS_STATE => 'WordPress state issue',
            HealthCheck::CHECK_TYPE_CRITICAL_FINDINGS => 'Critical findings detected',
        ];

        return $titles[$checkType] ?? 'Incident detected';
    }

    private function generateIncidentDescription(array $check): string
    {
        $type = $check['check_type'];
        $value = $check['value'] ?? [];

        $reason = $value['reason'] ?? null;

        if ($reason) {
            return "Check '{$type}' failed: {$reason}.";
        }

        return "Check '{$type}' failed.";
    }
}
