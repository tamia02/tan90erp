<?php

namespace Database\Factories;

use App\Models\VendorMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorMaster>
 */
class VendorMasterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_name' => $this->faker->unique()->company(),
            'gst_number' => strtoupper($this->faker->bothify('##???####?#?#')),
            'contact_phone' => '+91 '.$this->faker->numerify('##### #####'),
            'category' => 'Raw Material — PCM Compound',
            'active' => true,
        ];
    }
}
