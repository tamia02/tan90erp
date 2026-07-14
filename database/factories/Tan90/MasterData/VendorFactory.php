<?php

namespace Database\Factories\Tan90\MasterData;

use App\Models\Tan90\MasterData\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'code' => 'VEN-' . strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->company(),
            'gstin' => $this->faker->numerify('##AAAAA####A#Z#'),
            'gst_status' => 'pending',
            'category' => $this->faker->word(),
            'state' => $this->faker->state(),
            'city' => $this->faker->city(),
            'email' => $this->faker->unique()->companyEmail(),
            'portal_enabled' => 'No',
            'status' => 'active',
            'approval_status' => 'draft',
        ];
    }
}
