<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'schedule_json' => ['sometimes', 'array', $this->rejectUnexpectedScheduleKeys()],
            'schedule_json.every_minutes' => ['required_with:schedule_json', 'integer', 'min:1'],
            'schedule_json.starts_at_utc' => ['required_with:schedule_json', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mutable = array_intersect(
                array_keys($this->all()),
                ['name', 'enabled', 'schedule_json'],
            );

            if ($mutable === []) {
                $validator->errors()->add('name', 'At least one of name, enabled, or schedule_json is required.');
            }
        });
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
