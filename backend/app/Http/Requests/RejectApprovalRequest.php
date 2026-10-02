<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class RejectApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'not_regex:/^\s*$/', 'max:1000'],
            'status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
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
