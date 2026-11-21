<?php

namespace App\Http\Requests;

use App\Enums\ChoiceList;
use App\Models\Server;
use App\Rules\IpAddressIsFree;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add / edit server (SPEC §3.2–3.4, §13.6). Routes are admin only. The
 * hostname is the machine's own name, so a bare `mainserver` is as valid as
 * `web06.media24.si`.
 */
class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->route('server');
        $server = $server instanceof Server ? $server : null;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')->withoutTrashed()->ignore($server)],
            'hostname' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.?$/i'],
            'provider' => ['nullable', 'string', 'max:100'],
            'os_choice_id' => ['nullable', 'integer', Rule::exists('choices', 'id')->where('list', ChoiceList::Os->value)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'versions_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'addresses' => ['required', 'array', 'min:1', 'max:20'],
            'addresses.*.ip' => ['required', 'string', 'ip', 'distinct:ignore_case', new IpAddressIsFree($server)],
            'addresses.*.is_primary' => ['required', 'boolean'],
            'backup_location_ids' => ['array'],
            'backup_location_ids.*' => ['integer', 'distinct', Rule::exists('choices', 'id')->where('list', ChoiceList::BackupLocation->value)],
        ];
    }

    /**
     * Exactly one address is primary (SPEC §3.3).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $addresses = $this->input('addresses');

                if (! is_array($addresses) || $validator->errors()->has('addresses')) {
                    return;
                }

                $primaries = collect($addresses)->filter(fn ($address) => is_array($address) && filter_var($address['is_primary'] ?? false, FILTER_VALIDATE_BOOL))->count();

                if ($primaries !== 1) {
                    $validator->errors()->add('addresses', 'Mark exactly one address as primary.');
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
            'os_choice_id' => 'operating system',
            'addresses.*.ip' => 'IP address',
            'backup_location_ids.*' => 'backup location',
        ];
    }
}
