<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\DataImportJob;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\ItemCategory;
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
}
