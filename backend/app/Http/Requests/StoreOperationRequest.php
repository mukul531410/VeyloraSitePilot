<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_type' => ['required', 'string', Rule::in(['action.cache_clear'])],
            'target_json' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}