<?php

namespace App\Http\Requests;

use App\Rules\RegistrableDomain;
use App\Support\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add domain (SPEC §10.5): a new registrable domain with the subdomains the
 * admin reviewed, saved together.
 */
class AddDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Hostname::normalize($this->input('name'))]);
        }

        $sites = $this->input('sites');

        if (is_array($sites)) {
            $this->merge(['sites' => array_map(fn (mixed $site): mixed => is_array($site) ? [
                ...$site,
                'hostname' => is_string($site['hostname'] ?? null) ? Hostname::normalize($site['hostname']) : ($site['hostname'] ?? null),
                'port' => ($site['port'] ?? '') === '' ? null : $site['port'],
                'path' => ($site['path'] ?? '') === '' ? '/' : $site['path'],
            ] : $site, $sites)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:253', 'regex:'.SiteRequest::HOSTNAME_PATTERN,
                Rule::unique('domains', 'name')->withoutTrashed(), new RegistrableDomain,
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'sites' => ['present', 'array', 'max:100'],
            'sites.*.hostname' => [
                'required', 'string', 'max:253', 'distinct', 'regex:'.SiteRequest::HOSTNAME_PATTERN,
                Rule::unique('sites', 'hostname')->withoutTrashed(),
            ],
            'sites.*.scheme' => ['required', Rule::in(['https', 'http'])],
            'sites.*.port' => ['nullable', 'integer', 'between:1,65535'],
            'sites.*.path' => ['required', 'string', 'max:255', 'starts_with:/'],
            'sites.*.front_service_id' => ['nullable', 'integer', Rule::exists('external_services', 'id')->whereNull('deleted_at')],
            'sites.*.server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->whereNull('deleted_at')],
            'sites.*.expected_target' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * Every hostname must be the domain or one of its subdomains.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $name = (string) $this->input('name');

                foreach ((array) $this->input('sites') as $index => $site) {
                    $hostname = is_array($site) ? (string) ($site['hostname'] ?? '') : '';

                    if (! $validator->errors()->has("sites.{$index}.hostname") && $hostname !== $name && ! str_ends_with($hostname, '.'.$name)) {
                        $validator->errors()->add("sites.{$index}.hostname", 'Must be '.Hostname::display($name).' or one of its subdomains.');
                    }
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
            'sites.*.hostname' => 'hostname',
            'sites.*.front_service_id' => 'front service',
            'sites.*.server_id' => 'backend server',
            'sites.*.expected_target' => 'redirect target',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Enter a domain such as svet24.si.',
            'name.unique' => 'This domain is already in Sextant.',
            'sites.*.hostname.regex' => 'Enter a hostname such as podjetje.svet24.si.',
            'sites.*.hostname.unique' => 'This hostname is already a site.',
            'sites.*.hostname.distinct' => 'This hostname is listed twice.',
        ];
    }
}
