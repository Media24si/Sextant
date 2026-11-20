<?php

namespace App\Http\Requests;

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Models\ExternalService;
use App\Models\ServerAddress;
use App\Rules\IpOrCidr;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Add / edit external service (SPEC §3.5). Routes are admin only.
 */
class ExternalServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ranges' => $this->lines('ranges'),
            'cname_suffixes' => array_map(
                fn (string $suffix): string => '.'.ltrim(rtrim(mb_strtolower($suffix), '.'), '.'),
                $this->lines('cname_suffixes'),
            ),
            'response_headers' => array_map('mb_strtolower', $this->lines('response_headers')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $service = $this->route('external_service');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('external_services', 'name')->withoutTrashed()->ignore($service instanceof ExternalService ? $service : null)],
            'kind' => ['required', Rule::enum(ExternalServiceKind::class)],
            'ranges' => ['array', 'max:500'],
            'ranges.*' => ['string', 'distinct', new IpOrCidr],
            'ranges_source' => ['nullable', Rule::enum(RangeSource::class)],
            'cname_suffixes' => ['array', 'max:50'],
            'cname_suffixes.*' => ['string', 'max:253', 'distinct', 'regex:/^(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/'],
            'response_headers' => ['array', 'max:50'],
            'response_headers.*' => ['string', 'max:100', 'distinct', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Ranges must not cover one of our servers (SPEC §3.3, from the other side).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ranges = array_values(array_filter((array) $this->input('ranges'), fn ($range) => is_string($range) && IpOrCidr::passes($range)));

                if ($ranges === []) {
                    return;
                }

                $covered = ServerAddress::query()->whereHas('server')->with('server')->get()
                    ->first(fn (ServerAddress $address): bool => IpUtils::checkIp($address->ip, $ranges));

                if ($covered !== null) {
                    $validator->errors()->add('ranges', "These ranges include {$covered->ip}, an address of our server {$covered->server->name}.");
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
            'ranges.*' => 'range',
            'ranges_source' => 'published ranges',
            'cname_suffixes.*' => 'CNAME suffix',
            'response_headers.*' => 'header name',
        ];
    }

    /**
     * Textarea input: one value per line (or comma separated), blanks dropped.
     *
     * @return list<string>
     */
    private function lines(string $key): array
    {
        $value = $this->input($key);
        $values = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);

        return array_values(array_filter(array_map(fn ($line) => trim((string) $line), $values ?: []), fn (string $line) => $line !== ''));
    }
}
