<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AutomationRuleException;
use App\Http\Requests\StoreAutomationRuleRequest;
use App\Http\Requests\UpdateAutomationRuleRequest;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Http\Request;

class AutomationRulesController extends BaseController
{
    public function __construct(
        private AutomationRuleService $ruleService,
    ) {}

    public function index(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = $this->visibleRulesQuery($actor)->withCount('runs');

        if ($request->query('site_id') !== null) {
            $query->where('site_id', (string) $request->query('site_id'));
        }

        $rules = $query->orderBy('id')->paginate($this->perPage($request));

        return $this->successResponse(
            $rules->getCollection()
                ->map(fn (AutomationRule $rule) => $this->transformRule($rule))
                ->values()
                ->all(),
            $this->paginationMeta($rules)
        );
    }

    public function store(StoreAutomationRuleRequest $request)
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $rule = $this->ruleService->create($actor, $request->validated());
        } catch (AutomationRuleException $exception) {
            return $this->ruleExceptionResponse($exception);
        }

        return $this->successResponse($this->transformRule($rule->loadCount('runs')), [], 201);
    }

    public function show(Request $request, string $rule)
    {
        /** @var User $actor */
        $actor = $request->user();
        $automationRule = $this->visibleRulesQuery($actor)->withCount('runs')->find($rule);

        if ($automationRule === null) {
            return $this->errorResponse('Automation rule not found', 'not_found', 404);
        }

        return $this->successResponse($this->transformRule($automationRule));
    }

    public function update(UpdateAutomationRuleRequest $request, string $rule)
    {
        /** @var User $actor */
        $actor = $request->user();
        $automationRule = $this->visibleRulesQuery($actor)->find($rule);

        if ($automationRule === null) {
            return $this->errorResponse('Automation rule not found', 'not_found', 404);
        }

        try {
            $updated = $this->ruleService->update($actor, $automationRule, $request->validated());
        } catch (AutomationRuleException $exception) {
            return $this->ruleExceptionResponse($exception);
        }

        return $this->successResponse($this->transformRule($updated->loadCount('runs')));
    }

    public function destroy(Request $request, string $rule)
    {
        /** @var User $actor */
        $actor = $request->user();
        $automationRule = $this->visibleRulesQuery($actor)->find($rule);

        if ($automationRule === null) {
            return $this->errorResponse('Automation rule not found', 'not_found', 404);
        }

        try {
            $this->ruleService->delete($actor, $automationRule);
        } catch (AutomationRuleException $exception) {
            return $this->ruleExceptionResponse($exception);
        }

        return $this->successResponse(null);
    }

    /**
     * Reads follow the automation run contract: active membership in the active
     * organization that owns the active site. Anything else is simply not found,
     * so the endpoint cannot be used to probe another tenant.
     */
    private function visibleRulesQuery(User $actor)
    {
        return AutomationRule::query()
            ->whereHas('site', function ($siteQuery) use ($actor): void {
                $siteQuery->where('status', 'active')
                    ->whereHas('organization', function ($organizationQuery) use ($actor): void {
                        $organizationQuery->where('status', 'active')
                            ->whereHas('members', function ($memberQuery) use ($actor): void {
                                $memberQuery->where('user_id', $actor->id)
                                    ->where('status', 'active');
                            });
                    });
            });
    }

    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page');

        if (! is_numeric($requested)) {
            return 25;
        }

        return max(1, min(100, (int) $requested));
    }

    private function transformRule(AutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $rule->site_id,
            'name' => $rule->name,
            'enabled' => (bool) $rule->enabled,
            'trigger_type' => $rule->trigger_type,
            'schedule_json' => $rule->schedule_json,
            'conditions_json' => $rule->conditions_json,
            'action_type' => $rule->action_type,
            'target_json' => $rule->target_json,
            'created_by' => $rule->created_by,
            'runs_count' => (int) ($rule->runs_count ?? 0),
            'deletable' => (int) ($rule->runs_count ?? 0) === 0,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    private function ruleExceptionResponse(AutomationRuleException $exception)
    {
        if (in_array($exception->reason, [
            AutomationRuleException::WRONG_TENANT,
            AutomationRuleException::RULE_MISSING,
            AutomationRuleException::ORGANIZATION_MISSING,
            AutomationRuleException::SITE_MISSING,
        ], true)) {
            return $this->errorResponse('Automation rule not found', 'not_found', 404);
        }

        if (in_array($exception->reason, AutomationRuleException::UNAUTHORIZED_REASONS, true)) {
            return $this->errorResponse('Unauthorized', 'unauthorized', 403);
        }

        if (in_array($exception->reason, AutomationRuleException::VALIDATION_REASONS, true)) {
            return $this->errorResponse('The given data was invalid.', 'validation_error', 422, [
                'reason' => $exception->reason,
            ]);
        }

        if ($exception->reason === AutomationRuleException::RULE_HAS_RUNS) {
            return $this->errorResponse(
                'Automation rule has runs and cannot be deleted; disable it instead',
                'rule_has_runs',
                409,
                ['reason' => $exception->reason],
            );
        }

        return $this->errorResponse(
            'Automation rule request could not be completed',
            'rule_request_failed',
            409,
            ['reason' => $exception->reason],
        );
    }
}
