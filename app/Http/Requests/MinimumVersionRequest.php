<?php

namespace App\Http\Requests;

use App\Enums\Software;
use App\Models\MinimumVersion;
use App\Versions\VersionComparator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A minimum version (SPEC §3.15, §11.6): one per software, with an upstream
 * version per branch such as `8.2.36, 8.3.37` (or a kernel's `6.8.0-49`).
 * Typed as one text field; stored as a sorted list.
 */
class MinimumVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $versions = $this->input('min_versions');
        $versions = is_array($versions) ? $versions : (preg_split('/[\s,]+/', trim((string) $versions), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $versions = array_values(array_unique(array_map(fn (mixed $version): string => trim((string) $version), $versions)));
        $software = Software::tryFrom((string) $this->input('software'));

        if ($software !== null) {
            usort($versions, fn (string $a, string $b): int => VersionComparator::compare($software, $a, $b));
        }

        $this->merge([
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
        $minimum = $this->route('minimum_version');

        return [
            'software' => [
                'required',
                Rule::in(array_map(fn (Software $software): string => $software->value, array_filter(Software::cases(), fn (Software $software): bool => $software->hasMinimum()))),
                Rule::unique('minimum_versions', 'software')->ignore($minimum instanceof MinimumVersion ? $minimum : null),
            ],
            'min_versions' => ['required', 'array', 'min:1', 'max:20', $this->onePerBranch(...)],
            'min_versions.*' => ['string', 'max:50', 'regex:/^\d+(\.\d+)*(-\d+)?$/'],
            'cve_url' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function onePerBranch(string $attribute, mixed $versions, Closure $fail): void
    {
        $software = Software::tryFrom((string) $this->input('software'));

        if ($software === null || ! is_array($versions)) {
            return;
        }

        $seen = [];

        foreach ($versions as $version) {
            $branch = VersionComparator::branch($software, (string) $version);

            if ($branch === null) {
                continue;
            }

            if (isset($seen[$branch])) {
                $fail("One version per branch: {$seen[$branch]} and {$version} are both on {$branch}.");

                return;
            }

            $seen[$branch] = $version;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'software.unique' => 'This software already has a minimum; change that one instead.',
            'min_versions.required' => 'Enter at least one version, e.g. 8.2.36.',
            'min_versions.max' => 'Enter at most 20 versions.',
            'min_versions.*.regex' => 'Enter upstream versions such as 1.26.2 (or 6.8.0-49 for a kernel), separated by commas.',
            'min_versions.*.max' => 'A version is at most 50 characters.',
        ];
    }
}
