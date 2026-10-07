<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ConnectorCapability;
use App\Models\SiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectorCapabilityReportService
{
    /** @param array<int, array{key: string, supported: bool|int|string}> $capabilities */
    public function report(SiteConnection $source, string $connectorVersion, string $reportedAt, array $capabilities): SiteConnection
    {
        return DB::transaction(function () use ($source, $connectorVersion, $reportedAt, $capabilities): SiteConnection {
            $connection = SiteConnection::query()->lockForUpdate()->findOrFail($source->getKey());
            abort_unless($connection->isActive(), 401, 'Connector is inactive or revoked.');

            $time = Carbon::parse($reportedAt)->utc();
            $reportedKeys = [];

            foreach ($capabilities as $item) {
                $key = $item['key'];
                $supported = filter_var($item['supported'], FILTER_VALIDATE_BOOLEAN);
                $reportedKeys[] = $key;

                $capability = $connection->capabilities()->where('capability_key', $key)->lockForUpdate()->first();
                $isNew = $capability === null;
                $oldSupported = $capability?->reported_supported;
                if ($isNew) {
                    $capability = new ConnectorCapability([
                        'site_connection_id' => $connection->id,
                        'capability_key' => $key,
                        'enabled' => false,
                        'discovered_at' => now(),
                    ]);
                    $connection->capabilities()->save($capability);
                }

                $capability->forceFill([
                    'reported_supported' => $supported,
                    'reported_at' => $time,
                ])->save();

                if ($isNew || $oldSupported !== $supported) {
                    $this->auditChange($connection, $capability, $oldSupported, $supported, $connectorVersion, $time);
                }
            }

            $omitted = $connection->capabilities()
                ->whereNotNull('reported_at')
                ->whereNotIn('capability_key', $reportedKeys)
                ->lockForUpdate()
                ->get();

            foreach ($omitted as $capability) {
                $oldSupported = $capability->reported_supported;
                $capability->forceFill([
                    'reported_supported' => false,
                    'reported_at' => $time,
                ])->save();

                if ($oldSupported !== false) {
                    $this->auditChange($connection, $capability, $oldSupported, false, $connectorVersion, $time);
                }
            }

            $connection->update(['connector_version' => $connectorVersion]);

            return $connection->refresh();
        });
    }

    private function auditChange(
        SiteConnection $connection,
        ConnectorCapability $capability,
        ?bool $oldSupported,
        bool $newSupported,
        string $connectorVersion,
        Carbon $reportedAt,
    ): void {
        $site = $connection->site;
        AuditLog::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'action' => 'connector_capability_report_changed',
            'target_type' => 'connector_capability',
            'target_id' => $capability->id,
            'correlation_id' => (string) Str::ulid(),
            'before_json' => $oldSupported === null ? null : ['reported_supported' => $oldSupported],
            'after_json' => ['reported_supported' => $newSupported],
            'metadata_json' => [
                'connection_id' => $connection->id,
                'capability_key' => $capability->capability_key,
                'old_reported_supported' => $oldSupported,
                'new_reported_supported' => $newSupported,
                'enabled' => $capability->enabled,
                'effective' => $capability->isEffective(),
                'connector_version' => $connectorVersion,
                'reported_at' => $reportedAt->toIso8601String(),
            ],
        ]);
    }
}
