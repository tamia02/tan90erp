<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

class LegalEntityFactory extends Factory
{
    protected $model = LegalEntity::class;

    public function definition(): array
    {
        return [
            'code' => 'T90-' . strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->company(),
            'gstin' => $this->faker->numerify('##AAAAA####A#Z#'),
            'pan' => strtoupper($this->faker->bothify('?????####?')),
            'country' => 'India',
            'state' => $this->faker->state(),
            'base_currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'fiscal_year' => 'April-March',
            'status' => 'active',
            'approval_status' => 'draft',
        ];
    }
}
