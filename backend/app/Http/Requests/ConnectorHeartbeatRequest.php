<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectorHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'connector_version' => ['required', 'string', 'max:255'],
            'wordpress_version' => ['nullable', 'string', 'max:255'],
            'php_version' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['online', 'offline', 'error'])],
        ];
    }
}
