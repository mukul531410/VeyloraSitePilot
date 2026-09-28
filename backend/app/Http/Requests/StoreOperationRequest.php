<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $target = $this->input('target_json', []);
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
            'operation_type' => ['required', 'string', Rule::in(['action.cache_clear'])],
            'target_json' => ['required', 'array'],
            'target_json.cache_type' => ['required', 'string', Rule::in(['wordpress'])],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
