<?php

namespace App\Http\Requests;

use App\Checks\Argument;
use App\Checks\ArgumentType;
use App\Checks\CheckerRegistry;
use App\Models\Concerns\Monitorable;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add / edit monitor (SPEC §3.8, §4.6). Arguments and thresholds are
 * overrides: an empty field inherits the registry value. A field left out of
 * an edit keeps its current value. Argument values are validated with the
 * checker's schema rules.
 */
class MonitorRequest extends FormRequest
{
    private const RESOURCES = ['server' => Server::class, 'domain' => Domain::class, 'site' => Site::class];

    public function authorize(): bool
    {
        return $this->user()?->can('manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $arguments = $this->input('arguments');

        // Empty fields inherit: drop them before validating.
        if (is_array($arguments)) {
            $this->merge(['arguments' => array_filter($arguments, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [])]);
        }

        if ($this->has('label')) {
            $this->merge(['label' => $this->filled('label') ? trim((string) $this->input('label')) : null]);
        }

        foreach (['interval_minutes', 'failures_before_incident', 'successes_before_resolve'] as $field) {
            if ($this->has($field) && ! $this->filled($field)) {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->monitor() === null;
        $type = $this->resourceType();

        return [
            ...($creating ? [
                'monitorable_type' => ['required', Rule::in(array_keys(self::RESOURCES))],
                'monitorable_id' => ['required', 'integer'],
                'checker' => ['required', 'string', Rule::in($type === null ? [] : app(CheckerRegistry::class)->keysFor($type))],
            ] : []),
            'label' => ['nullable', 'string', 'max:100'],
            'arguments' => ['array'],
            ...$this->argumentRules(),
            'interval_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'failures_before_incident' => ['nullable', 'integer', 'between:1,20'],
            'successes_before_resolve' => ['nullable', 'integer', 'between:1,20'],
            'enabled' => ['boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $resource = $this->resource();

                if ($this->monitor() === null && $resource === null && ! $validator->errors()->has('monitorable_type')) {
                    $validator->errors()->add('monitorable_id', 'That resource no longer exists.');

                    return;
                }

                foreach (array_keys((array) $this->input('arguments')) as $key) {
                    if (! in_array($key, array_map(fn (Argument $argument): string => $argument->name, $this->schema()), true)) {
                        $validator->errors()->add("arguments.{$key}", "{$key} is not an argument of this checker.");
                    }
                }

                $label = $this->has('label') ? $this->input('label') : $this->monitor()?->label;
                $duplicate = $resource?->monitors()
                    ->where('checker', $this->checker())
                    ->where('label', $label)
                    ->when($this->monitor() !== null, fn ($query) => $query->whereKeyNot($this->monitor()?->id))
                    ->exists();

                if ($duplicate === true) {
                    $validator->errors()->add('label', 'This resource already has this check'.($label === null ? '; give this one a label.' : ' with that label.'));
                }
            },
        ];
    }

    /**
     * Validated overrides, cast to their schema types.
     *
     * @return array<string, mixed>|null
     */
    public function arguments(): ?array
    {
        $values = [];
        $schema = collect($this->schema())->keyBy('name');

        foreach ((array) $this->validated('arguments', []) as $key => $value) {
            $argument = $schema->get($key);

            $values[$key] = match ($argument?->type) {
                ArgumentType::Int => (int) $value,
                ArgumentType::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                ArgumentType::IntList => array_values(array_map('intval', (array) $value)),
                ArgumentType::StringList => array_values(array_map('strval', (array) $value)),
                default => $value,
            };
        }

        return $values === [] ? null : $values;
    }

    public function resource(): (Model&Monitorable)|null
    {
        if ($this->monitor() !== null) {
            return $this->monitor()->resource();
        }

        $class = self::RESOURCES[$this->resourceType() ?? ''] ?? null;

        /** @var (Model&Monitorable)|null $resource */
        $resource = $class === null ? null : $class::query()->find($this->integer('monitorable_id'));

        return $resource;
    }

    private function monitor(): ?Monitor
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor ? $monitor : null;
    }

    private function resourceType(): ?string
    {
        $type = $this->monitor()->monitorable_type ?? $this->input('monitorable_type');

        return is_string($type) ? $type : null;
    }

    private function checker(): string
    {
        return $this->monitor()->checker ?? (string) $this->input('checker');
    }

    /**
     * @return list<Argument>
     */
    private function schema(): array
    {
        $registry = app(CheckerRegistry::class);

        return $registry->has($this->checker()) ? $registry->checkerClass($this->checker())::arguments() : [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function argumentRules(): array
    {
        $rules = [];

        foreach ($this->schema() as $argument) {
            foreach ($argument->validationRules("arguments.{$argument->name}") as $field => $fieldRules) {
                // Every argument is optional here: missing = inherit.
                $rules[$field] = ['sometimes', ...array_values(array_filter($fieldRules, fn (mixed $rule): bool => ! in_array($rule, ['present', 'required', 'nullable'], true)))];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->schema() as $argument) {
            $attributes["arguments.{$argument->name}"] = strtolower($argument->label);
        }

        return [...$attributes, 'interval_minutes' => 'interval'];
    }
}
