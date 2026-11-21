<?php

namespace App\Checks;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One argument in a checker's schema (SPEC §4.6). Defaults live in the
 * registry, not here.
 */
final class Argument
{
    private ?string $help = null;

    private bool $nullable = false;

    /** @var list<mixed> */
    private array $rules = [];

    /**
     * @param  array<string|int, string>|(Closure(Model): iterable<string|int, string>)|null  $options
     */
    private function __construct(
        public readonly ArgumentType $type,
        public readonly string $name,
        public readonly string $label,
        private readonly array|Closure|null $options = null,
    ) {}

    public static function int(string $name, string $label): self
    {
        return new self(ArgumentType::Int, $name, $label);
    }

    public static function string(string $name, string $label): self
    {
        return new self(ArgumentType::String, $name, $label);
    }

    public static function bool(string $name, string $label): self
    {
        return new self(ArgumentType::Bool, $name, $label);
    }

    public static function intList(string $name, string $label): self
    {
        return new self(ArgumentType::IntList, $name, $label);
    }

    public static function stringList(string $name, string $label): self
    {
        return new self(ArgumentType::StringList, $name, $label);
    }

    /**
     * @param  array<string|int, string>|(Closure(Model): iterable<string|int, string>)  $options  value => label
     */
    public static function select(string $name, string $label, array|Closure $options): self
    {
        return new self(ArgumentType::Select, $name, $label, $options);
    }

    /**
     * @param  list<mixed>  $rules  Laravel validation rules for the value
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Rules for the value, plus `.*` rules for list items, keyed by field.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(string $field): array
    {
        $base = match ($this->type) {
            ArgumentType::Int => ['integer'],
            ArgumentType::String, ArgumentType::Select => ['string'],
            ArgumentType::Bool => ['boolean'],
            ArgumentType::IntList, ArgumentType::StringList => ['array'],
        };

        $rules = [$field => [$this->nullable ? 'nullable' : 'present', ...$base, ...$this->rules]];

        if ($this->type === ArgumentType::IntList) {
            $rules["{$field}.*"] = ['integer'];
        } elseif ($this->type === ArgumentType::StringList) {
            $rules["{$field}.*"] = ['string', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function optionsFor(?Model $resource): array
    {
        $options = $this->options instanceof Closure
            ? ($resource === null ? [] : ($this->options)($resource))
            : ($this->options ?? []);

        $plain = [];

        foreach ($options as $value => $label) {
            $plain[(string) $value] = $label;
        }

        return $plain;
    }

    /**
     * JSON for the Vue `MonitorArgumentsForm` (§4.6).
     *
     * @return array{type: string, name: string, label: string, help: string|null, nullable: bool, options: array<string, string>|null, default: mixed}
     */
    public function toArray(?Model $resource, mixed $default): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'label' => $this->label,
            'help' => $this->help,
            'nullable' => $this->nullable,
            'options' => $this->type === ArgumentType::Select ? $this->optionsFor($resource) : null,
            'default' => $default,
        ];
    }
}
