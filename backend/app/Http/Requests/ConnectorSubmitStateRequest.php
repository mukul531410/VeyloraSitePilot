<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectorSubmitStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'read_at' => ['required', 'date'],
            'cache_generation' => ['required', 'string', 'min:1'],
            'cleared_types' => ['required', 'array', 'min:1'],
            'cleared_types.*' => ['required', 'string'],
            'cache_state' => ['required', 'array', 'size:6'],
            'wp_version' => ['sometimes', 'nullable', 'string'],
            'connector_version' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
