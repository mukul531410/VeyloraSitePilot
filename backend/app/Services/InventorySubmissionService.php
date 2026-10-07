<?php

namespace App\Services;

use App\Models\InventorySnapshot;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteCoreState;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use Illuminate\Support\Facades\DB;

/**
 * Inventory is a Level 0 read-only sync. It records what the connector observed
 * and never claims remote execution, so it creates no Operation, touches no
 * OperationService/PolicyEngine boundary and requires no approval.
 */
class InventorySubmissionService
{
    public const CAPABILITY_CORE = 'read.wordpress';

    public const CAPABILITY_PLUGINS = 'read.plugins';

    public const CAPABILITY_THEMES = 'read.themes';

    public function __construct(private AvailableUpdateFindingService $availableUpdateFindings) {}

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{snapshot?: InventorySnapshot, created?: bool}|array{error: array{0: string, 1: string, 2: int}}
     */
    public function submit(SiteConnection $connection, array $inventory): array
    {
        return DB::transaction(function () use ($connection, $inventory): array {
            // Serialize per site so two concurrent submissions cannot both observe
            // the same "latest snapshot" and create duplicate history.
            $site = Site::query()->whereKey($connection->site_id)->lockForUpdate()->first();
            if ($site === null) {
                return ['error' => ['Site not found', 'not_found', 404]];
            }

            $lockedConnection = SiteConnection::query()->whereKey($connection->id)->lockForUpdate()->first();
            if ($lockedConnection === null || ! $lockedConnection->isActive()) {
                return ['error' => ['Connector is inactive or revoked', 'unauthorized', 401]];
            }

            $invalid = $this->capabilityDenied($lockedConnection, $inventory);
            if ($invalid !== null) {
                return $invalid;
            }

            $shapeError = $this->validateShape($inventory);
            if ($shapeError !== null) {
                return $shapeError;
            }

            $checksum = $this->checksum($inventory);
            $existing = InventorySnapshot::query()
                ->where('site_id', $site->id)
                ->where('status', InventorySnapshot::STATUS_COMPLETED)
                ->where('checksum', $checksum)
                ->orderByDesc('completed_at')
                ->first();

            if ($existing !== null) {
                // A connector retry of an identical submission replays instead of
                // writing a second identical snapshot.
                return ['snapshot' => $existing, 'created' => false];
            }

            $snapshot = $this->persist($site, $inventory, $checksum);
            $this->availableUpdateFindings->process($snapshot);

            return ['snapshot' => $snapshot, 'created' => true];
        });
    }

    /** @return array{error: array{0: string, 1: string, 2: int}}|null */
    private function capabilityDenied(SiteConnection $connection, array $inventory): ?array
    {
        $completeness = $inventory['category_completeness'] ?? [];
        $required = [self::CAPABILITY_CORE];
        if (! empty($inventory['plugins']) || $this->isExplicitlyComplete($completeness['plugins'] ?? null)) {
            $required[] = self::CAPABILITY_PLUGINS;
        }
        if (! empty($inventory['themes']) || $this->isExplicitlyComplete($completeness['themes'] ?? null)) {
            $required[] = self::CAPABILITY_THEMES;
        }

        foreach ($required as $capability) {
            $capabilityRow = $connection->capabilities()
                ->where('capability_key', $capability)
                ->first();

            if (! $capabilityRow?->isEffective()) {
                return ['error' => ['Capability not granted', 'capability_denied', 403]];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{error: array{0: string, 1: string, 2: int}}|null
     */
    private function validateShape(array $inventory): ?array
    {
        $startedAt = strtotime((string) $inventory['started_at']);
        $completedAt = isset($inventory['completed_at']) && $inventory['completed_at'] !== null
            ? strtotime((string) $inventory['completed_at'])
            : time();

        if ($startedAt === false || $completedAt === false || $completedAt < $startedAt) {
            return ['error' => ['completed_at must not precede started_at', 'invalid_inventory', 422]];
        }

        $completeness = $inventory['category_completeness'] ?? [];
        foreach (['plugins', 'themes'] as $category) {
            if ($this->isExplicitlyComplete($completeness[$category] ?? null) && ! array_key_exists($category, $inventory)) {
                return ['error' => ["{$category} must be present when declared complete", 'invalid_inventory', 422]];
            }
        }

        foreach (['plugins' => 'key', 'themes' => 'key'] as $section => $keyField) {
            $seen = [];

            foreach ($inventory[$section] ?? [] as $item) {
                $key = (string) ($item[$keyField] ?? '');

                if (in_array($key, $seen, true)) {
                    return ['error' => ["Duplicate {$section} key in one snapshot", 'invalid_inventory', 422]];
                }

                $seen[] = $key;
            }
        }

        return null;
    }

    private function isExplicitlyComplete(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** @param array<string, mixed> $inventory */
    private function persist(Site $site, array $inventory, string $checksum): InventorySnapshot
    {
        $startedAt = strtotime((string) $inventory['started_at']);
        $completedAt = isset($inventory['completed_at']) && $inventory['completed_at'] !== null
            ? strtotime((string) $inventory['completed_at'])
            : time();

        $snapshot = InventorySnapshot::query()->create([
            'site_id' => $site->id,
            'snapshot_type' => InventorySnapshot::SNAPSHOT_TYPE_FULL,
            'started_at' => date('Y-m-d H:i:s', $startedAt),
            'completed_at' => date('Y-m-d H:i:s', $completedAt),
            'status' => InventorySnapshot::STATUS_COMPLETED,
            'checksum' => $checksum,
            'wordpress_complete' => $inventory['category_completeness']['wordpress'] ?? null,
            'plugins_complete' => $inventory['category_completeness']['plugins'] ?? null,
            'themes_complete' => $inventory['category_completeness']['themes'] ?? null,
        ]);

        $wordpress = $inventory['wordpress'];

        SiteCoreState::query()->create([
            'site_id' => $site->id,
            'inventory_snapshot_id' => $snapshot->id,
            'wordpress_version' => $wordpress['version'] ?? null,
            'php_version' => $wordpress['php_version'] ?? null,
            'update_available' => (bool) ($wordpress['update_available'] ?? false),
            'update_available_reported' => array_key_exists('update_available', $wordpress),
            'status' => (string) ($wordpress['status'] ?? 'active'),
        ]);

        foreach ($inventory['plugins'] ?? [] as $plugin) {
            SitePlugin::query()->create([
                'site_id' => $site->id,
                'inventory_snapshot_id' => $snapshot->id,
                'plugin_key' => (string) $plugin['key'],
                'name' => (string) $plugin['name'],
                'version' => $plugin['version'] ?? null,
                'update_available' => (bool) ($plugin['update_available'] ?? false),
                'update_available_reported' => array_key_exists('update_available', $plugin),
                'active' => (bool) ($plugin['active'] ?? false),
                'status' => (string) ($plugin['status'] ?? 'active'),
                'metadata_json' => $plugin['metadata'] ?? null,
            ]);
        }

        foreach ($inventory['themes'] ?? [] as $theme) {
            SiteTheme::query()->create([
                'site_id' => $site->id,
                'inventory_snapshot_id' => $snapshot->id,
                'theme_key' => (string) $theme['key'],
                'name' => (string) $theme['name'],
                'version' => $theme['version'] ?? null,
                'update_available' => (bool) ($theme['update_available'] ?? false),
                'update_available_reported' => array_key_exists('update_available', $theme),
                'active' => (bool) ($theme['active'] ?? false),
                'status' => (string) ($theme['status'] ?? 'active'),
                'metadata_json' => $theme['metadata'] ?? null,
            ]);
        }

        return $snapshot;
    }

    /**
     * Deterministic digest of the canonical payload. Recreated only by the
     * canonicalizer already used for authoritative connector state, so an
     * unchanged inventory replays rather than duplicating history.
     *
     * @param  array<string, mixed>  $inventory
     */
    private function checksum(array $inventory): string
    {
        return hash('sha256', json_encode($this->canonicalize($inventory), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
