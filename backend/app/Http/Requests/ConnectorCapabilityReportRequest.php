<?php

namespace App\Http\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConnectorCapabilityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'connector_version' => ['required', 'string', 'max:255'],
            'reported_at' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->isUtcRfc3339($value)) {
                        $fail('The reported_at field must be an RFC3339 UTC timestamp.');
                    }
                },
            ],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'array:key,supported'],
            'capabilities.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/'],
            'capabilities.*.supported' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = [];
            foreach ($this->input('capabilities', []) as $index => $capability) {
                if (! is_array($capability) || ! isset($capability['key']) || ! is_string($capability['key'])) {
                    continue;
                }
                if (isset($keys[$capability['key']])) {
                    $validator->errors()->add("capabilities.{$index}.key", 'Capability keys must be unique within a report.');
                }
                $keys[$capability['key']] = true;
            }
        }];
    }

    /** @return array{connector_version: string, reported_at: string, capabilities: array<int, array{key: string, supported: bool|int|string}>} */
    public function report(): array
    {
        return $this->validated();
    }

    private function isUtcRfc3339(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/', $value)) {
            return false;
        }

        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (\Throwable) {
            return false;
        }

        return $timestamp->getOffset() === 0
            && $timestamp->format('Y-m-d\TH:i:s') === substr($value, 0, 19)
            && $timestamp->getTimezone()->getName() !== '';
    }
}
