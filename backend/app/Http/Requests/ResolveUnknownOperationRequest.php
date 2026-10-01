<?php

namespace App\Http\Requests;

use App\Models\Operation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class ResolveUnknownOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'in:'.implode(',', Operation::RESOLUTIONS)],
            'reason' => ['required', 'string', 'not_regex:/^\s*$/', 'max:1000'],
            'organization_id' => ['prohibited'],
            'site_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'operation_id' => ['prohibited'],
            'status' => ['prohibited'],
            'attempt_status' => ['prohibited'],
            'attempt_id' => ['prohibited'],
            'verification_status' => ['prohibited'],
            'resolved_by' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'resolution_reason' => ['prohibited'],
        ];
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
