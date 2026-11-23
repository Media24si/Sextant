<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Rules\RegistrableDomain;
use App\Support\Hostname;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add / edit domain (SPEC §3.6). Names are compared as stored: lowercase punycode.
 */
class DomainRequest extends FormRequest
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
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $domain = $this->route('domain');

        return [
            'name' => [
                'required', 'string', 'max:253',
                'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+(xn--[a-z0-9-]{1,59}|[a-z]{2,63})$/',
                'bail',
                new RegistrableDomain,
                Rule::unique('domains', 'name')->withoutTrashed()->ignore($domain instanceof Domain ? $domain : null),
                $this->keepsItsSites($domain instanceof Domain ? $domain : null),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * A site's domain is the registrable part of its hostname, so a domain
     * with sites can't take another name: its sites would point at a domain
     * their hostnames aren't under, and its checks would watch another name.
     */
    private function keepsItsSites(?Domain $domain): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($domain): void {
            if ($domain === null || $value === $domain->name) {
                return;
            }

            $sites = $domain->sites()->count();

            if ($sites > 0) {
                $fail("{$domain->display_name} has {$sites} ".str('site')->plural($sites).', so its name can\'t change. Add the new domain instead.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'Enter a domain such as svet24.si.'];
    }
}
