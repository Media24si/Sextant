<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A read-only GitHub token for one owner (SPEC §3.20). Only fine-grained
 * tokens: a classic token can't be limited to chosen repositories or to
 * reading.
 */
class GithubTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner' => trim((string) $this->input('owner')),
            'token' => trim((string) $this->input('token')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'owner' => ['required', 'string', 'max:39', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/'],
            'token' => ['required', 'string', 'max:255', 'regex:/^github_pat_[A-Za-z0-9_]{20,}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner.regex' => 'Enter the organisation or user name as it appears in github.com/{owner}.',
            'token.regex' => 'Use a fine-grained token (it starts with github_pat_) with read-only access to Contents.',
        ];
    }
}
