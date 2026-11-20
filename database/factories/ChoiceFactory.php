<?php

namespace Database\Factories;

use App\Enums\ChoiceList;
use App\Models\Choice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Choice>
 */
class ChoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'list' => ChoiceList::Os,
            'label' => fake()->unique()->words(3, true),
            'position' => fake()->numberBetween(100, 999),
        ];
    }

    public function backupLocation(): static
    {
        return $this->state(fn (array $attributes) => ['list' => ChoiceList::BackupLocation]);
    }
}
