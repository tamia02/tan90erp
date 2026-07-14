<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\BusinessUnit;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Plant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlantFactory extends Factory
{
    protected $model = Plant::class;

    public function definition(): array
    {
        return [
            'code' => 'PLANT-' . strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->city() . ' Manufacturing Unit',
            'tan90_business_unit_id' => BusinessUnit::factory(),
            'tan90_location_id' => Location::factory(),
            'plant_type' => 'Manufacturing Unit',
            'manager' => $this->faker->name(),
            'shift_model' => 'General',
            'status' => 'active',
            'approval_status' => 'approved',
        ];
    }
}
