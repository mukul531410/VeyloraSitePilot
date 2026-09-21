<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectorTelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'observations' => ['required', 'array', 'min:1'],
            'observations.*.check_type' => ['required', 'string', Rule::in([
                'reachability',
                'http_response',
                'ssl',
                'heartbeat',
                'wordpress_state',
                'critical_findings',
            ])],
            'observations.*.status' => ['required', 'string', Rule::in(['pass', 'warn', 'fail'])],
            'observations.*.value' => ['nullable', 'array'],
            'observations.*.checked_at' => ['nullable', 'date'],
        ];
    }
}
