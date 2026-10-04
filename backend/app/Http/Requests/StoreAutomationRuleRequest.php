<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `trigger_type`, `action_type` and `conditions_json` are deliberately absent:
 * they are server-controlled so a client can never widen what the engine
 * supports. Ignored keys are dropped by `$request->validated()`.
 */
class StoreAutomationRuleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $target = $this->input('target_json');
        if ($target === null) {
            $target = [];
        }
        if (is_array($target) && ! array_key_exists('cache_type', $target)) {
            $target['cache_type'] = 'wordpress';
        }
        $this->merge(['target_json' => $target]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'site_id' => ['required', 'string', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'schedule_json' => ['required', 'array', $this->rejectUnexpectedScheduleKeys()],
            'schedule_json.every_minutes' => ['required', 'integer', 'min:1'],
            'schedule_json.starts_at_utc' => ['required', 'string'],
            'target_json' => ['required', 'array'],
            'target_json.cache_type' => ['required', 'string', Rule::in(['wordpress'])],
        ];
    }

    /**
     * `$request->validated()` silently drops nested keys that no rule mentions,
     * so an unexpected schedule key would be truncated instead of rejected.
     * `ScheduledOccurrenceResolver` treats the key set as exact, so the API
     * refuses it here rather than quietly rewriting the client's schedule.
     */
    private function rejectUnexpectedScheduleKeys(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $unexpected = array_diff(array_keys($value), ['every_minutes', 'starts_at_utc']);

            if ($unexpected !== []) {
                $fail('schedule_json may only contain every_minutes and starts_at_utc.');
            }
        };
    }
}
