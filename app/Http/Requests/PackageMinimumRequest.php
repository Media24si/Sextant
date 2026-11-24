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
 * release line such as `12.13, 13.39` (per major) or `8.1.7, 8.2.5, 8.3.7`
 * (per minor). Typed as one text field; stored as a sorted list. Composer
 * names are stored lowercase, as Composer treats them.
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
            'min_versions' => ['required', 'array', 'min:1', 'max:20', $this->onePerLine(...)],
            'min_versions.*' => ['string', 'max:50', 'regex:/^\d+(\.\d+)*$/'],
            'cve_url' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * No two versions on the same line (`8.3.5, 8.3.7`), and none on a line
     * another one already covers (`8.13` is for all of 8, so `8.3.7` with it
     * would contradict it).
     *
     * @param  Closure(string): mixed  $fail
     */
    private function onePerLine(string $attribute, mixed $versions, Closure $fail): void
    {
        /** @var array<string, string> $seen line => version */
        $seen = [];

        foreach ((array) $versions as $version) {
            $line = PackageVersion::line((string) $version);

            if ($line === null) {
                continue;
            }

            foreach ($seen as $seenLine => $seenVersion) {
                if ($seenLine === $line) {
                    $fail("One version per line: {$seenVersion} and {$version} are both {$line}.x.");

                    return;
                }

                [$wide, $wideVersion, $narrowVersion] = substr_count($seenLine, '.') < substr_count($line, '.') ? [$seenLine, $seenVersion, $version] : [$line, $version, $seenVersion];

                if (str_starts_with(PackageVersion::line($narrowVersion).'.', $wide.'.')) {
                    $fail("{$wideVersion} covers every {$wide}.x, so {$narrowVersion} can't be listed with it.");

                    return;
                }
            }

            $seen[$line] = (string) $version;
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
