<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'code' => 'LOC-' . strtoupper($this->faker->unique()->lexify('???')),
            'name' => $this->faker->city(),
            'type' => 'Manufacturing',
            'state' => $this->faker->state(),
            'city' => $this->faker->city(),
            'pincode' => $this->faker->numerify('######'),
            'gstin' => $this->faker->numerify('##AAAAA####A#Z#'),
            'gst_status' => 'pending',
            'status' => 'active',
            'approval_status' => 'approved',
        ];
    }
}
