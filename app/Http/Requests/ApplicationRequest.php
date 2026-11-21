<?php

namespace App\Http\Requests;

use App\Models\Application;
use App\Rules\GithubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add / edit application (SPEC §3.19). Routes are admin only.
 */
class ApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'github_url' => $this->filled('github_url') ? rtrim(trim((string) $this->input('github_url')), '/') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $application = $this->route('application');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('applications', 'name')->withoutTrashed()->ignore($application instanceof Application ? $application : null)],
            'description' => ['nullable', 'string', 'max:500'],
            'github_url' => ['nullable', 'string', 'max:255', new GithubRepositoryUrl],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['github_url' => 'GitHub repository'];
    }
}
