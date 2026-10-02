<?php

namespace App\Http\Requests;

use App\Models\AutomationRunRecovery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class RecoverAutomationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:'.implode(',', AutomationRunRecovery::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:1000'],
            // The server resolves the Operation through immutable provenance only.
            'operation_id' => ['prohibited'],
            'status' => ['prohibited'],
            'run_status' => ['prohibited'],
            'occurrence_key' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'site_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (trim((string) $this->header('Idempotency-Key')) === '') {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header is required.');
            }

            if ($this->input('action') === AutomationRunRecovery::ACTION_ABANDON
                && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add('reason', 'A reason is required to abandon a stranded run.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $requestId = $this->header('X-Request-ID') ?: (string) Str::uuid();

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_error',
                'message' => 'The given data was invalid.',
                'details' => $validator->errors()->toArray(),
            ],
            'request_id' => $requestId,
        ], 422)->header('X-Request-ID', $requestId));
    }
}
