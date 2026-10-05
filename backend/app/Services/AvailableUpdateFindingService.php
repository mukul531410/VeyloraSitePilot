<?php

namespace App\Services;

use App\Models\AvailableUpdate;
use App\Models\InventorySnapshot;
use Carbon\CarbonInterface;

/**
 * Derives current update findings from the immutable rows of one accepted
 * inventory snapshot. The caller owns the transaction and site-level lock.
 */
class AvailableUpdateFindingService
{
    public function process(InventorySnapshot $snapshot): void
    {
        if ($snapshot->status !== InventorySnapshot::STATUS_COMPLETED) {
            return;
        }

        $observedAt = $snapshot->completed_at ?? $snapshot->created_at;
        $siteId = $snapshot->site_id;

        $core = $snapshot->coreState()->first();
        if ($core?->update_available_reported === true) {
            $this->applyObservation(
                $siteId,
                AvailableUpdate::TYPE_CORE,
                'wordpress',
                $core->update_available,
                $observedAt,
            );
        } elseif ($core === null && $snapshot->wordpress_complete === true) {
            $this->resolveAbsent($siteId, AvailableUpdate::TYPE_CORE, [], $observedAt);
        }

        $plugins = $snapshot->plugins()->get();
        foreach ($plugins as $plugin) {
            if ($plugin->update_available_reported === true) {
                $this->applyObservation(
                    $siteId,
                    AvailableUpdate::TYPE_PLUGIN,
                    $plugin->plugin_key,
                    $plugin->update_available,
                    $observedAt,
                );
            }
        }
        if ($snapshot->plugins_complete === true) {
            $this->resolveAbsent(
                $siteId,
                AvailableUpdate::TYPE_PLUGIN,
                $plugins->pluck('plugin_key')->all(),
                $observedAt,
            );
        }

        $themes = $snapshot->themes()->get();
        foreach ($themes as $theme) {
            if ($theme->update_available_reported === true) {
                $this->applyObservation(
                    $siteId,
                    AvailableUpdate::TYPE_THEME,
                    $theme->theme_key,
                    $theme->update_available,
                    $observedAt,
                );
            }
        }
        if ($snapshot->themes_complete === true) {
            $this->resolveAbsent(
                $siteId,
                AvailableUpdate::TYPE_THEME,
                $themes->pluck('theme_key')->all(),
                $observedAt,
            );
        }
    }

    private function applyObservation(
        string $siteId,
        string $type,
        string $identifier,
        bool $updateAvailable,
        CarbonInterface $observedAt,
    ): void {
        $finding = AvailableUpdate::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->where('item_identifier', $identifier)
            ->first();

        if (! $updateAvailable) {
            if ($finding?->status === AvailableUpdate::STATUS_OPEN) {
                $finding->update([
                    'status' => AvailableUpdate::STATUS_RESOLVED,
                    'resolved_at' => $observedAt,
                ]);
            }

            return;
        }

        if ($finding === null) {
            AvailableUpdate::query()->create([
                'site_id' => $siteId,
                'type' => $type,
                'item_identifier' => $identifier,
                'severity' => AvailableUpdate::SEVERITY_INFO,
                'status' => AvailableUpdate::STATUS_OPEN,
                'first_seen_at' => $observedAt,
                'last_seen_at' => $observedAt,
            ]);

            return;
        }

        $changes = [
            'severity' => AvailableUpdate::SEVERITY_INFO,
            'last_seen_at' => $observedAt,
        ];
        if ($finding->status === AvailableUpdate::STATUS_RESOLVED) {
            $changes['status'] = AvailableUpdate::STATUS_OPEN;
            $changes['resolved_at'] = null;
        }

        if ($finding->severity !== AvailableUpdate::SEVERITY_INFO
            || $finding->last_seen_at?->ne($observedAt)
            || $finding->status === AvailableUpdate::STATUS_RESOLVED) {
            $finding->update($changes);
        }
    }

    /** @param  list<string>  $observedIdentifiers */
    private function resolveAbsent(
        string $siteId,
        string $type,
        array $observedIdentifiers,
        CarbonInterface $observedAt,
    ): void {
        $query = AvailableUpdate::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->where('status', AvailableUpdate::STATUS_OPEN);

        if ($observedIdentifiers !== []) {
            $query->whereNotIn('item_identifier', $observedIdentifiers);
        }

        $query->update([
            'status' => AvailableUpdate::STATUS_RESOLVED,
            'resolved_at' => $observedAt,
            'updated_at' => now(),
        ]);
    }
}
