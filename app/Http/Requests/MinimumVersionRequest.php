<?php

namespace App\Http\Requests;

use App\Enums\Software;
use App\Models\MinimumVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A minimum version (SPEC §3.15, §11.6): a branch only for PHP; the version
 * as an upstream number such as `1.26.2` or a kernel's `6.8.0-49`.
 */
class MinimumVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch' => $this->input('software') === Software::Php->value ? trim((string) $this->input('branch')) : '',
            'min_version' => trim((string) $this->input('min_version')),
            'cve_url' => $this->filled('cve_url') ? trim((string) $this->input('cve_url')) : null,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $minimum = $this->route('minimum_version');

        return [
            'software' => ['required', Rule::in(array_map(fn (Software $software): string => $software->value, array_filter(Software::cases(), fn (Software $software): bool => $software->hasMinimum())))],
            'branch' => [
                'present', 'string', 'max:20',
                Rule::requiredIf($this->input('software') === Software::Php->value),
                'regex:/^(\d+\.\d+)?$/',
                Rule::unique('minimum_versions', 'branch')
                    ->where('software', (string) $this->input('software'))
                    ->ignore($minimum instanceof MinimumVersion ? $minimum : null),
            ],
            'min_version' => ['required', 'string', 'max:50', 'regex:/^\d+(\.\d+)*(-\d+)?$/'],
            'cve_url' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch.required' => 'Choose the PHP branch, e.g. 8.2.',
            'branch.regex' => 'A PHP branch looks like 8.2.',
            'branch.unique' => 'This software already has a minimum; change that one instead.',
            'min_version.regex' => 'Enter an upstream version such as 1.26.2 (or 6.8.0-49 for a kernel).',
        ];
    }
}
