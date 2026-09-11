<?php

namespace Tests\Feature;

use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\ItemCategory;
use App\Models\Tan90\MasterData\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client's explicit requirement (see the 2026-07-27 migration this
 * builds on): chemical/raw-material identity must never appear in the app,
 * not even via a "clever" abbreviation — only an opaque masking_code like
 * RM-0001. That migration generated codes once, for items that existed at
 * the time; every item created since (any source — confirmed live, the Zoho
 * sync's 185 real items all had masking_code=null) silently had none.
 * Item::booted() now guarantees one on every create, matching the same
 * category-prefix + sequential scheme the original migration used.
 */
class ItemMaskingCodeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(string $name): ItemCategory
    {
        return ItemCategory::create([
            'code' => strtoupper(str($name)->slug()), 'name' => $name,
            'qc_required' => 'No', 'batch_tracking' => 'Optional', 'approval_status' => 'approved',
        ]);
    }

    private function makeUom(): Uom
    {
        return Uom::firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'base_uom' => 'EA', 'conversion_factor' => 1, 'approval_status' => 'approved']);
    }

    public function test_a_new_item_gets_a_masking_code_automatically(): void
    {
        $category = $this->makeCategory('Chemicals');
        $uom = $this->makeUom();

        $item = Item::create([
            'sku' => 'TEST-SKU-1', 'code' => 'IT-1', 'name' => 'Test Chemical',
            'tan90_item_category_id' => $category->id, 'tan90_uom_id' => $uom->id,
            'status' => 'active', 'approval_status' => 'draft',
        ]);

        $this->assertSame('RM-0001', $item->masking_code);
    }

    public function test_masking_codes_increment_sequentially_per_prefix(): void
    {
        $category = $this->makeCategory('Finished Goods');
        $uom = $this->makeUom();

        $first = Item::create(['sku' => 'FG-1', 'code' => 'IT-1', 'name' => 'Item 1', 'tan90_item_category_id' => $category->id, 'tan90_uom_id' => $uom->id, 'status' => 'active', 'approval_status' => 'draft']);
        $second = Item::create(['sku' => 'FG-2', 'code' => 'IT-2', 'name' => 'Item 2', 'tan90_item_category_id' => $category->id, 'tan90_uom_id' => $uom->id, 'status' => 'active', 'approval_status' => 'draft']);

        $this->assertSame('FG-0001', $first->masking_code);
        $this->assertSame('FG-0002', $second->masking_code);
    }

    public function test_an_unmapped_category_falls_back_to_the_md_prefix(): void
    {
        $category = $this->makeCategory('Some Unmapped Category');
        $uom = $this->makeUom();

        $item = Item::create(['sku' => 'MD-1', 'code' => 'IT-1', 'name' => 'Item 1', 'tan90_item_category_id' => $category->id, 'tan90_uom_id' => $uom->id, 'status' => 'active', 'approval_status' => 'draft']);

        $this->assertSame('MD-0001', $item->masking_code);
    }

    public function test_an_explicitly_set_masking_code_is_not_overwritten(): void
    {
        $category = $this->makeCategory('Chemicals');
        $uom = $this->makeUom();

        $item = Item::create([
            'sku' => 'TEST-SKU-2', 'code' => 'IT-2', 'name' => 'Test Item', 'masking_code' => 'RM-9999',
            'tan90_item_category_id' => $category->id, 'tan90_uom_id' => $uom->id,
            'status' => 'active', 'approval_status' => 'draft',
        ]);

        $this->assertSame('RM-9999', $item->masking_code);
    }
}
