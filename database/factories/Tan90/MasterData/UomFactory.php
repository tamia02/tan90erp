<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\Uom;
use Illuminate\Database\Eloquent\Factories\Factory;

class UomFactory extends Factory
{
    protected $model = Uom::class;

    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('???'));

        return [
            'code' => $code,
            'name' => ucfirst($this->faker->word()),
            'type' => 'Count',
            'base_uom' => $code,
            'conversion_factor' => 1,
            'decimal_places' => 0,
            'status' => 'active',
            'approval_status' => 'approved',
        ];
    }
}
