<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition(): array
    {
        return [
            'code' => 'CAT-' . strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->words(2, true),
            'valuation_method' => 'FIFO',
            'qc_required' => 'Yes',
            'batch_tracking' => 'Optional',
            'status' => 'active',
            'approval_status' => 'approved',
        ];
    }
}
