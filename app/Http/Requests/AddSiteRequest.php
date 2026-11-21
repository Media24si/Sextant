<?php

namespace App\Http\Requests;

use App\Checks\CheckerRegistry;
use App\Detection\PublicSuffixList;
use App\Models\Site;
use App\Rules\GithubRepositoryUrl;
use App\Support\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The reviewed values from Add site (SPEC §10.4). The domain follows from the
 * hostname through the Public Suffix List, so the browser never picks it.
 */
class AddSiteRequest extends FormRequest
{
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
            'path' => $this->filled('path') ? $this->input('path') : '/',
            'github_url' => $this->filled('github_url') ? rtrim(trim((string) $this->input('github_url')), '/') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => [
                'required', 'string', 'max:253', 'regex:'.SiteRequest::HOSTNAME_PATTERN,
                Rule::unique('sites', 'hostname')->withoutTrashed(),
            ],
            'scheme' => ['required', Rule::in(['https', 'http'])],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'path' => ['required', 'string', 'max:255', 'starts_with:/'],
            'front_service_id' => ['nullable', 'integer', Rule::exists('external_services', 'id')->whereNull('deleted_at')],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'github_url' => ['nullable', 'string', 'max:255', new GithubRepositoryUrl],
            'expected_target' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'add_www' => ['boolean'],
            'skip_checkers' => ['array'],
            'skip_checkers.*' => ['string', Rule::in($this->defaultCheckers())],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('hostname')) {
                    return;
                }

                $hostname = (string) $this->input('hostname');

                if ($this->registrableDomain() === null) {
                    $validator->errors()->add('hostname', "{$hostname} is a public suffix, not a site.");
                }

                if ($this->boolean('add_www') && Site::query()->where('hostname', 'www.'.$hostname)->exists()) {
                    $validator->errors()->add('add_www', "www.{$hostname} is already a site.");
                }
            },
        ];
    }

    /**
     * The site and domain default checkers Add site can leave off (SPEC §4.4).
     *
     * @return list<string>
     */
    private function defaultCheckers(): array
    {
        $registry = app(CheckerRegistry::class);

        return array_column([...$registry->defaults('site'), ...$registry->defaults('domain')], 'checker');
    }

    public function registrableDomain(): ?string
    {
        return app(PublicSuffixList::class)->registrableDomain((string) $this->input('hostname'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'front_service_id' => 'front service',
            'server_id' => 'backend server',
            'github_url' => 'GitHub repository',
            'expected_target' => 'redirect target',
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
