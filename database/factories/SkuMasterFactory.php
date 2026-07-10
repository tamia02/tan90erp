<?php

namespace Database\Factories;

use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuMaster>
 */
class SkuMasterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => $this->faker->unique()->words(3, true),
            'category' => 'PCM — Raw Material',
            'unit' => 'KG',
            'mapped' => true,
        ];
    }
}
