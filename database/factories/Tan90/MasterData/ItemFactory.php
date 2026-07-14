<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\ItemCategory;
use App\Models\Tan90\MasterData\Uom;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $sku = strtoupper($this->faker->unique()->bothify('SKU-####??'));

        return [
            'sku' => $sku,
            'code' => $sku,
            'name' => $this->faker->words(3, true),
            'tan90_item_category_id' => ItemCategory::factory(),
            'tan90_uom_id' => Uom::factory(),
            'qc_required' => 'Yes',
            'batch_tracking' => 'Optional',
            'standard_cost' => $this->faker->randomFloat(2, 10, 500),
            'status' => 'active',
            'approval_status' => 'draft',
        ];
    }
}
