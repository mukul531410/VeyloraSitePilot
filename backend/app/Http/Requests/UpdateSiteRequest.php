<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'string', 'url', 'max:2048'],
            'environment' => ['sometimes', 'string', Rule::in(['development', 'staging', 'production'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'deprovisioned'])],
            'business_criticality' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
            'timezone' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
