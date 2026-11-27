<?php

namespace App\Http\Requests;

use App\Checks\Argument;
use App\Checks\ArgumentOverrides;
use App\Checks\CheckerRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Save a checker's defaults (Monitor defaults page). Like a monitor's
 * overrides: an empty field keeps the config value. Arguments that depend on
 * the resource, such as a server address, can't be set here.
 */
class CheckerSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $arguments = $this->input('arguments');

        if (is_array($arguments)) {
            $this->merge(['arguments' => ArgumentOverrides::withoutEmpty($arguments)]);
        }

        foreach (['interval_minutes', 'failures_before_incident', 'successes_before_resolve'] as $field) {
            if (! $this->filled($field)) {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'arguments' => ['array'],
            ...ArgumentOverrides::rules($this->schema()),
            'interval_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'failures_before_incident' => ['nullable', 'integer', 'between:1,20'],
            'successes_before_resolve' => ['nullable', 'integer', 'between:1,20'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $names = array_map(fn (Argument $argument): string => $argument->name, $this->schema());

                foreach (array_keys((array) $this->input('arguments')) as $key) {
                    if (! in_array($key, $names, true)) {
                        $validator->errors()->add("arguments.{$key}", "{$key} can't have a default for this checker.");
                    }
                }
            },
        ];
    }

    /**
     * Validated argument defaults, cast to their schema types.
     *
     * @return array<string, mixed>|null
     */
    public function arguments(): ?array
    {
        return ArgumentOverrides::cast($this->schema(), (array) $this->validated('arguments', []));
    }

    /**
     * The checker's arguments that can have a checker-wide default.
     *
     * @return list<Argument>
     */
    public function schema(): array
    {
        $registry = app(CheckerRegistry::class);
        $checker = (string) $this->route('checker');

        if (! $registry->has($checker)) {
            return [];
        }

        return array_values(array_filter($registry->checkerClass($checker)::arguments(), fn (Argument $argument): bool => ! $argument->isPerResource()));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...ArgumentOverrides::attributes($this->schema()), 'interval_minutes' => 'interval'];
    }
}
