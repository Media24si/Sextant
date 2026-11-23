<?php

namespace App\Http\Requests;

use App\Enums\Ecosystem;
use App\Models\PackageMinimum;
use App\Packages\PackageVersion;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A package minimum (SPEC §3.23, §11.9): one per package, with a version per
 * major such as `12.13, 13.39`. Typed as one text field; stored as a sorted
 * list. Composer names are stored lowercase, as Composer treats them.
 */
class PackageMinimumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $versions = $this->input('min_versions');
        $versions = is_array($versions) ? $versions : (preg_split('/[\s,]+/', trim((string) $versions), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $versions = array_values(array_unique(array_map(fn (mixed $version): string => ltrim(trim((string) $version), 'vV'), $versions)));
        usort($versions, PackageVersion::compare(...));
        $name = trim((string) $this->input('name'));

        $this->merge([
            'name' => $this->input('ecosystem') === Ecosystem::Composer->value ? strtolower($name) : $name,
            'min_versions' => $versions,
            'cve_url' => $this->filled('cve_url') ? trim((string) $this->input('cve_url')) : null,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $minimum = $this->route('package_minimum');

        return [
            'ecosystem' => ['required', Rule::enum(Ecosystem::class)],
            'name' => [
                'required',
                'string',
                'max:214',
                $this->input('ecosystem') === Ecosystem::Composer->value
                    ? 'regex:/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/'
                    : 'regex:/^(@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/i',
                Rule::unique('package_minimums', 'name')
                    ->where('ecosystem', (string) $this->input('ecosystem'))
                    ->ignore($minimum instanceof PackageMinimum ? $minimum : null),
            ],
            'min_versions' => ['required', 'array', 'min:1', 'max:20', $this->onePerMajor(...)],
            'min_versions.*' => ['string', 'max:50', 'regex:/^\d+(\.\d+)*$/'],
            'cve_url' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function onePerMajor(string $attribute, mixed $versions, Closure $fail): void
    {
        $seen = [];

        foreach ((array) $versions as $version) {
            $major = PackageVersion::branch((string) $version);

            if ($major === null) {
                continue;
            }

            if (isset($seen[$major])) {
                $fail("One version per major: {$seen[$major]} and {$version} are both {$major}.x.");

                return;
            }

            $seen[$major] = $version;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => $this->input('ecosystem') === Ecosystem::Composer->value
                ? 'Enter the package as vendor/name, e.g. laravel/framework.'
                : 'Enter the npm package name, e.g. axios or @inertiajs/vue3.',
            'name.unique' => 'This package already has a minimum; change that one instead.',
            'min_versions.required' => 'Enter at least one version, e.g. 12.13.',
            'min_versions.max' => 'Enter at most 20 versions.',
            'min_versions.*.regex' => 'Enter versions such as 12.13 or 13.39.1, separated by commas.',
            'min_versions.*.max' => 'A version is at most 50 characters.',
        ];
    }
}
