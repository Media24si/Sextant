<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Models\Site;
use App\Rules\GithubRepositoryUrl;
use App\Support\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add / edit site by hand (SPEC §3.7, P1.15). Phase 2's Add site detects
 * most of this from a URL (§10).
 */
class SiteRequest extends FormRequest
{
    /** Lowercase punycode with at least one dot. */
    public const HOSTNAME_PATTERN = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+(xn--[a-z0-9-]{1,59}|[a-z]{2,63})$/';

    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hostname'))) {
            $this->merge(['hostname' => Hostname::normalize($this->input('hostname'))]);
        }

        $this->merge([
            'port' => $this->filled('port') ? $this->input('port') : null,
            'github_url' => $this->filled('github_url') ? rtrim(trim((string) $this->input('github_url')), '/') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $site = $this->route('site');

        return [
            'domain_id' => ['required', 'integer', Rule::exists('domains', 'id')->whereNull('deleted_at')],
            'application_id' => ['nullable', 'integer', Rule::exists('applications', 'id')->whereNull('deleted_at')],
            'hostname' => [
                'required', 'string', 'max:253',
                'regex:'.self::HOSTNAME_PATTERN,
                Rule::unique('sites', 'hostname')->withoutTrashed()->ignore($site instanceof Site ? $site : null),
            ],
            'scheme' => ['required', Rule::in(['https', 'http'])],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'front_service_id' => ['nullable', 'integer', Rule::exists('external_services', 'id')->whereNull('deleted_at')],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'github_url' => ['nullable', 'string', 'max:255', new GithubRepositoryUrl],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The hostname must be the domain or one of its subdomains.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['hostname', 'domain_id'])) {
                    return;
                }

                $domain = Domain::query()->find($this->integer('domain_id'));
                $hostname = (string) $this->input('hostname');

                if ($domain !== null && $hostname !== $domain->name && ! str_ends_with($hostname, '.'.$domain->name)) {
                    $validator->errors()->add('hostname', "The hostname must be {$domain->display_name} or one of its subdomains.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'domain_id' => 'domain',
            'application_id' => 'application',
            'front_service_id' => 'front service',
            'server_id' => 'backend server',
            'github_url' => 'GitHub repository',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['hostname.regex' => 'Enter a hostname such as podjetje.svet24.si.'];
    }
}
