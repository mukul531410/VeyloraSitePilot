<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AutomationRun;
use App\Models\AvailableUpdate;
use App\Models\Incident;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Models\OperationResult;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationDeliveryService
{
    public function deliver(string $sourceType, string $sourceId, string $type): void
    {
        try {
            $source = $this->source($sourceType, $sourceId);
            if ($source === null) {
                return;
            }

            [$organizationId, $siteId, $recipients, $severity, $title, $body] = $this->details($source, $type);
            if ($organizationId === null || $recipients->isEmpty()) {
                return;
            }

            $organizationActive = Organization::query()
                ->whereKey($organizationId)->where('status', 'active')->exists();
            if (! $organizationActive || ($siteId !== null && ! Site::query()->whereKey($siteId)
                ->where('status', 'active')->where('organization_id', $organizationId)->exists())) {
                return;
            }

            $channel = NotificationChannel::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'type' => NotificationChannel::TYPE_IN_APP],
                ['enabled' => true],
            );
            if (! $channel->enabled) {
                return;
            }

            foreach ($recipients->unique('id') as $recipient) {
                if ($recipient->status !== 'active') {
                    continue;
                }

                $membership = OrganizationMember::query()->where('organization_id', $organizationId)
                    ->where('user_id', $recipient->id)->where('status', 'active')->first();
                if ($membership === null) {
                    continue;
                }

                $preference = $recipient->notificationPreferences()
                    ->where('organization_id', $organizationId)
                    ->where('event_type', $type)
                    ->where('channel_id', $channel->id)
                    ->first();
                if ($preference?->enabled === false) {
                    continue;
                }

                Notification::query()->firstOrCreate([
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'type' => $type,
                    'user_id' => $recipient->id,
                ], [
                    'organization_id' => $organizationId,
                    'site_id' => $siteId,
                    'severity' => $severity,
                    'title' => $title,
                    'body' => $body,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Notification delivery failed.', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'notification_type' => $type,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function source(string $type, string $id): mixed
    {
        return match ($type) {
            'incident' => Incident::query()->with('site.organization')->find($id),
            'available_update' => AvailableUpdate::query()->with('site.organization')->find($id),
            'operation' => Operation::query()->with('site.organization')->find($id),
            'operation_result' => OperationResult::query()->with('operation.site.organization')->find($id),
            'approval_request' => ApprovalRequest::query()->with('operation.site.organization')->find($id),
            'automation_run' => AutomationRun::query()->with('site.organization')->find($id),
            default => null,
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: Collection, 3: string, 4: string, 5: string} */
    private function details(mixed $source, string $type): array
    {
        $site = match (true) {
            $source instanceof OperationResult => $source->operation?->site,
            $source instanceof ApprovalRequest => $source->operation?->site,
            default => $source->site ?? null,
        };
        $organization = $site?->organization;
        if ($organization === null) {
            return [null, null, collect(), 'info', '', ''];
        }

        $members = OrganizationMember::query()->with(['user', 'role'])
            ->where('organization_id', $organization->id)->where('status', 'active')->get()
            ->filter(fn (OrganizationMember $member): bool => $member->user?->status === 'active');
        if ($type === Notification::TYPE_APPROVAL_REQUESTED) {
            $requesterId = $source instanceof ApprovalRequest ? $source->requested_by : null;
            $members = $members->filter(fn (OrganizationMember $member): bool => in_array($member->role?->key, ['owner', 'admin'], true)
                && (string) $member->user_id !== (string) $requesterId);
        }
        $recipients = $members->pluck('user')->filter()->values();

        [$severity, $title, $body] = $this->copy($source, $type);

        return [$organization->id, $site->id, $recipients, $severity, $title, $body];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function copy(mixed $source, string $type): array
    {
        return match ($type) {
            Notification::TYPE_INCIDENT_DETECTED => [$source->severity, 'Site incident detected', $source->title],
            Notification::TYPE_INCIDENT_RESOLVED => ['info', 'Site incident resolved', $source->title],
            Notification::TYPE_AVAILABLE_UPDATE_DETECTED => ['info', 'WordPress update available', ucfirst($source->type).': '.$source->item_identifier],
            Notification::TYPE_OPERATION_FAILED => ['high', 'Operation failed', $source->operation_type.' failed.'],
            Notification::TYPE_OPERATION_VERIFICATION_FAILED => ['high', 'Operation verification failed', 'Verification failed for '.$source->operation->operation_type.'.'],
            Notification::TYPE_OPERATION_UNKNOWN => ['high', 'Operation outcome unknown', 'The outcome of '.$source->operation_type.' is unknown.'],
            Notification::TYPE_APPROVAL_REQUESTED => ['info', 'Approval requested', 'Review '.$source->operation->operation_type.' for '.$source->site->name.'.'],
            Notification::TYPE_AUTOMATION_FAILED => ['high', 'Automation run failed', $source->failure_message ?: ($source->failure_code ?: 'An automation run failed.')],
            default => ['info', 'SitePilot notification', 'A site event requires attention.'],
        };
    }
}
