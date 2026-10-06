<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationsController extends BaseController
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->status !== 'active') {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'in:unread,read'],
            'type' => ['sometimes', 'in:'.implode(',', Notification::TYPES)],
            'severity' => ['sometimes', 'in:info,low,medium,high,critical'],
            'site_id' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->errorResponse('Invalid notification filters.', 'validation_error', 422, $validator->errors()->toArray());
        }

        $query = $this->visibleNotifications($user);
        $status = $request->query('status', 'unread');
        $status === 'unread' ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
        foreach (['type', 'severity', 'site_id'] as $filter) {
            if ($request->query($filter) !== null) {
                $query->where($filter, $request->query($filter));
            }
        }

        $notifications = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) $request->query('per_page', 25));

        return $this->successResponse(
            $notifications->getCollection()->map(fn (Notification $notification): array => $this->transform($notification))->values()->all(),
            $this->paginationMeta($notifications),
        );
    }

    public function show(Request $request, string $notification)
    {
        if ($request->user()->status !== 'active') {
            return $this->errorResponse('Notification not found', 'not_found', 404);
        }
        $record = $this->visibleNotifications($request->user())->find($notification);
        if ($record === null) {
            return $this->errorResponse('Notification not found', 'not_found', 404);
        }

        return $this->successResponse($this->transform($record));
    }

    public function markRead(Request $request, string $notification)
    {
        if ($request->user()->status !== 'active') {
            return $this->errorResponse('Notification not found', 'not_found', 404);
        }
        $record = $this->visibleNotifications($request->user())->find($notification);
        if ($record === null) {
            return $this->errorResponse('Notification not found', 'not_found', 404);
        }
        if ($record->read_at === null) {
            $record->forceFill(['read_at' => now('UTC')])->save();
        }

        return $this->successResponse($this->transform($record->refresh()));
    }

    private function visibleNotifications(User $user)
    {
        return Notification::query()->where('user_id', $user->id)
            ->whereHas('organization', function ($query) use ($user): void {
                $query->where('status', 'active')->whereHas('members', function ($members) use ($user): void {
                    $members->where('user_id', $user->id)->where('status', 'active');
                });
            })
            ->where(function ($query): void {
                $query->whereNull('site_id')->orWhereHas('site', function ($site): void {
                    $site->where('status', 'active')->whereColumn('sites.organization_id', 'notifications.organization_id');
                });
            });
    }

    private function transform(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'organization_id' => $notification->organization_id,
            'user_id' => $notification->user_id,
            'site_id' => $notification->site_id,
            'source_type' => $notification->source_type,
            'source_id' => $notification->source_id,
            'type' => $notification->type,
            'severity' => $notification->severity,
            'title' => $notification->title,
            'body' => $notification->body,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
