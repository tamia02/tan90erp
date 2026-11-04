<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\DataImportJob;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\ItemCategory;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Models\Tan90\MasterData\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    private function csvFile(string $content, string $name = 'items.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tan90-csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_preview_flags_invalid_and_duplicate_rows(): void
    {
        Storage::fake('local');
        $category = ItemCategory::factory()->create();
        $uom = Uom::factory()->create();
        $user = $this->masterDataManager();

        $csv = "sku,code,name,tan90_item_category_id,tan90_uom_id,qc_required,batch_tracking\n"
            . "CSV-001,CSV-001,First Item,{$category->id},{$uom->id},Yes,Optional\n"
            . "CSV-001,CSV-001,Duplicate SKU,{$category->id},{$uom->id},Yes,Optional\n"
            . ",CSV-002,Missing SKU,{$category->id},{$uom->id},Yes,Optional\n";

        $response = $this->actingAs($user)->post(route('tan90.master-data.import.upload'), [
            'entity' => 'items',
            'file' => $this->csvFile($csv),
        ]);

        $job = DataImportJob::firstOrFail();
        $response->assertRedirect(route('tan90.master-data.import.show', $job->id));

        $this->assertSame(3, $job->total_rows);
        $this->assertSame(1, $job->valid_rows);
        $this->assertSame(1, $job->duplicate_rows);
        $this->assertSame(1, $job->invalid_rows);
    }

    public function test_commit_creates_only_valid_rows_as_draft_records(): void
    {
        Storage::fake('local');
        $category = ItemCategory::factory()->create();
        $uom = Uom::factory()->create();
        $user = $this->masterDataManager();

        $csv = "sku,code,name,tan90_item_category_id,tan90_uom_id,qc_required,batch_tracking\n"
            . "CSV-100,CSV-100,Importable Item,{$category->id},{$uom->id},Yes,Optional\n";

        $this->actingAs($user)->post(route('tan90.master-data.import.upload'), ['entity' => 'items', 'file' => $this->csvFile($csv)]);
        $job = DataImportJob::firstOrFail();

        $this->actingAs($user)->post(route('tan90.master-data.import.commit', $job->id));

        $item = Item::where('sku', 'CSV-100')->firstOrFail();
        $this->assertSame('draft', $item->approval_status);
        $this->assertSame('completed', $job->fresh()->result);
    }

    public function test_reuploading_the_same_file_is_idempotent(): void
    {
        Storage::fake('local');
        $category = ItemCategory::factory()->create();
        $uom = Uom::factory()->create();
        $user = $this->masterDataManager();

        $csv = "sku,code,name,tan90_item_category_id,tan90_uom_id,qc_required,batch_tracking\n"
            . "CSV-200,CSV-200,Idempotent Item,{$category->id},{$uom->id},Yes,Optional\n";

        $this->actingAs($user)->post(route('tan90.master-data.import.upload'), ['entity' => 'items', 'file' => $this->csvFile($csv)]);
        $this->actingAs($user)->post(route('tan90.master-data.import.upload'), ['entity' => 'items', 'file' => $this->csvFile($csv)]);

        $this->assertSame(1, DataImportJob::where('entity_type', 'items')->count(), 'Same file + entity must resolve to a single job.');
    }

    public function test_import_updates_existing_item_values_and_keeps_blank_columns_unchanged(): void
    {
        Storage::fake('local');
        $item = Item::factory()->create(['sku' => 'CSV-UPD-01', 'code' => 'CSV-UPD-01', 'name' => 'Old Name', 'storage' => 'Keep this', 'reorder_level' => '5']);
        $user = $this->masterDataManager();
        $csv = "sku,code,name,tan90_item_category_id,tan90_uom_id,qc_required,batch_tracking,storage,reorder_level\n"
            ."CSV-UPD-01,CSV-UPD-01,Updated Name,{$item->tan90_item_category_id},{$item->tan90_uom_id},Yes,Optional,,12\n";

        $this->actingAs($user)->post(route('tan90.master-data.import.upload'), ['entity' => 'items', 'file' => $this->csvFile($csv)]);
        $job = DataImportJob::firstOrFail();
        $this->assertSame(1, $job->valid_rows);
        $this->assertSame(1, $job->updated_rows);
        $this->assertSame('update', $job->rows()->first()->status);

        $this->actingAs($user)->post(route('tan90.master-data.import.commit', $job->id))->assertRedirect();
        $item->refresh();
        $this->assertSame('Updated Name', $item->name);
        $this->assertSame('Keep this', $item->storage, 'Blank CSV cells leave existing fields unchanged.');
        $this->assertSame('12', (string) $item->reorder_level);
        $this->assertSame('approved', $item->approval_status);
        $this->assertSame('imported', $job->rows()->first()->fresh()->status);
    }

    public function test_import_sends_approved_critical_field_changes_to_maker_checker(): void
    {
        Storage::fake('local');
        $item = Item::factory()->create(['sku' => 'CSV-CRIT-01', 'code' => 'CSV-CRIT-01', 'name' => 'Original', 'hsn' => '0101', 'approval_status' => 'approved']);
        $user = $this->masterDataManager();
        $csv = "sku,code,name,tan90_item_category_id,tan90_uom_id,qc_required,batch_tracking,hsn\n"
            ."CSV-CRIT-01,CSV-CRIT-01,Updated,{$item->tan90_item_category_id},{$item->tan90_uom_id},Yes,Optional,0202\n";

        $this->actingAs($user)->post(route('tan90.master-data.import.upload'), ['entity' => 'items', 'file' => $this->csvFile($csv)]);
        $job = DataImportJob::firstOrFail();
        $this->actingAs($user)->post(route('tan90.master-data.import.commit', $job->id))->assertRedirect();

        $this->assertSame('0101', $item->fresh()->hsn, 'Approved critical values remain unchanged until maker-checker approval.');
        $this->assertSame('Updated', $item->fresh()->name);
        $request = MasterChangeRequest::where('entity_type', 'items')->where('entity_id', $item->id)->firstOrFail();
        $this->assertSame('0202', $request->proposed_changes['hsn']);
    }
}
