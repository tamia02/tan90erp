<?php

namespace Database\Factories;

use App\Models\VendorSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorSubmission>
 */
class VendorSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => 'PO TEST '.$this->faker->unique()->numberBetween(1000, 9999),
            'vendor_name' => $this->faker->company(),
            'status' => 'submitted',
            'has_lr_pod' => false,
        ];
    }
}
