<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Validator;

class RetryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'operation_type' => ['missing'],
            'target_json' => ['missing'],
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

