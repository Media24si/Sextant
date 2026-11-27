<?php

namespace Database\Factories;

use App\Models\CheckerSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckerSetting>
 */
class CheckerSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checker' => 'site.ssl',
            'arguments' => ['warning_days' => 10, 'error_days' => 3],
        ];
    }
}
