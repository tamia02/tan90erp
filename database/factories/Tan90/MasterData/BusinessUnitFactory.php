<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\BusinessUnit;
use App\Models\Tan90\MasterData\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessUnitFactory extends Factory
{
    protected $model = BusinessUnit::class;

    public function definition(): array
    {
        return [
            'code' => 'BU-' . strtoupper($this->faker->unique()->lexify('???')),
            'name' => $this->faker->words(2, true),
            'tan90_legal_entity_id' => LegalEntity::factory(),
            'head' => $this->faker->name(),
            'cost_center' => 'CC-' . $this->faker->numberBetween(100, 999),
            'status' => 'active',
            'approval_status' => 'approved',
        ];
    }
}
