<?php

namespace Database\Factories;

use App\Models\GateEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateEntry>
 */
class GateEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gate_no' => 'GATE-'.$this->faker->unique()->numberBetween(1000, 9999),
            'entry_type' => 'inward',
            'vehicle_number' => 'MH '.$this->faker->numberBetween(1, 14).' '.$this->faker->bothify('??').' '.$this->faker->numberBetween(1000, 9999),
            'driver_name' => $this->faker->name(),
            'location' => 'Bhiwandi',
            'bill_scanned' => true,
            'status' => 'pending_validation',
            'sla_deadline' => now()->addHours(12),
        ];
    }
}
