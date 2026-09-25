<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectorSubmitResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['success', 'failed'])],
            'cache_cleared_at' => ['required_if:status,success', 'date', 'nullable'],
            'cleared_types' => ['required_if:status,success', 'array', 'min:1', 'nullable'],
            'cache_generation' => ['nullable', 'string'],
            'error_code' => ['required_if:status,failed', 'string', 'nullable'],
            'error_message' => ['nullable', 'string'],
        ];
    }
}