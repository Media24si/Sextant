<?php

namespace App\Checks;

/**
 * Argument overrides from a form (SPEC §4.5, §4.6): a monitor's, or a
 * checker's defaults. An empty field means "inherit", so every argument is
 * optional; values are validated with the schema's rules and cast to their
 * types.
 */
final class ArgumentOverrides
{
    /**
     * Drop the empty fields before validating.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function withoutEmpty(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Validation rules for `arguments.*`, every argument optional.
     *
     * @param  list<Argument>  $schema
     * @return array<string, list<mixed>>
     */
    public static function rules(array $schema): array
    {
        $rules = [];

        foreach ($schema as $argument) {
            foreach ($argument->validationRules("arguments.{$argument->name}") as $field => $fieldRules) {
                // Missing = inherit.
                $rules[$field] = ['sometimes', ...array_values(array_filter($fieldRules, fn (mixed $rule): bool => ! in_array($rule, ['present', 'required', 'nullable'], true)))];
            }
        }

        return $rules;
    }

    /**
     * Validated values cast to their schema types; null when there are none.
     *
     * @param  list<Argument>  $schema
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    public static function cast(array $schema, array $values): ?array
    {
        $types = [];

        foreach ($schema as $argument) {
            $types[$argument->name] = $argument->type;
        }

        $cast = [];

        foreach ($values as $key => $value) {
            $cast[$key] = match ($types[$key] ?? null) {
                ArgumentType::Int => (int) $value,
                ArgumentType::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                ArgumentType::IntList => array_values(array_map('intval', (array) $value)),
                ArgumentType::StringList => array_values(array_map('strval', (array) $value)),
                default => $value,
            };
        }

        return $cast === [] ? null : $cast;
    }

    /**
     * Form labels for the error messages, e.g. "warn this many days before expiry".
     *
     * @param  list<Argument>  $schema
     * @return array<string, string>
     */
    public static function attributes(array $schema): array
    {
        $attributes = [];

        foreach ($schema as $argument) {
            $attributes["arguments.{$argument->name}"] = strtolower($argument->label);
        }

        return $attributes;
    }
}
