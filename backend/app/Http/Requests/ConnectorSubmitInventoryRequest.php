<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectorSubmitInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],

            'category_completeness' => ['sometimes', 'array:wordpress,plugins,themes'],
            'category_completeness.wordpress' => ['sometimes', 'boolean'],
            'category_completeness.plugins' => ['sometimes', 'boolean'],
            'category_completeness.themes' => ['sometimes', 'boolean'],

            'wordpress' => ['required', 'array'],
            'wordpress.version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'wordpress.php_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'wordpress.update_available' => ['sometimes', 'boolean'],
            'wordpress.status' => ['sometimes', 'string', 'max:32'],

            'plugins' => ['sometimes', 'array'],
            'plugins.*.key' => ['required', 'string', 'max:191'],
            'plugins.*.name' => ['required', 'string', 'max:191'],
            'plugins.*.version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'plugins.*.update_available' => ['sometimes', 'boolean'],
            'plugins.*.active' => ['sometimes', 'boolean'],
            'plugins.*.status' => ['sometimes', 'string', 'max:32'],
            'plugins.*.metadata' => ['sometimes', 'nullable', 'array'],

            'themes' => ['sometimes', 'array'],
            'themes.*.key' => ['required', 'string', 'max:191'],
            'themes.*.name' => ['required', 'string', 'max:191'],
            'themes.*.version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'themes.*.update_available' => ['sometimes', 'boolean'],
            'themes.*.active' => ['sometimes', 'boolean'],
            'themes.*.status' => ['sometimes', 'string', 'max:32'],
            'themes.*.metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
