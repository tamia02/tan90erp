<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QcEvidenceImageRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_held_qc_line_cannot_progress_without_two_tagged_images(): void
    {
        Storage::fake('local');
        $qc = User::factory()->create(['role' => Role::Qc]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'inward',
            'status' => 'grn',
            'invoice_qty' => 10,
            'invoice_amount' => 100,
            'rate' => 10,
            'material' => 'PCM line A',
        ]);

        $this->actingAs($qc);
        $check = Volt::test('qc.queue')
            ->call('openCheck', $gate->id)
            ->set('accepted', '8')
            ->set('qcHold', '2')
            ->set('defective', '0')
            ->set('rejected', '0')
            ->set('holdReason', 'Package seal damaged');

        $check->call('submitCheck')->assertHasErrors('evidenceImages');
        $this->assertSame('grn', $gate->fresh()->status);

        $check->set('evidenceImages.0', UploadedFile::fake()->image('defective.png'))
            ->set('evidenceImageTypes.0', 'Defective Image')
            ->set('evidenceImages.1', UploadedFile::fake()->image('rejected.png'))
            ->set('evidenceImageTypes.1', 'Rejected Image')
            ->call('submitCheck')
            ->assertHasNoErrors();

        $qcResult = $gate->fresh()->qcResult;
        $this->assertSame('qc_done', $gate->fresh()->status);
        $this->assertCount(2, $qcResult->evidenceImageRecords);
        $this->assertSame(['Defective Image', 'Rejected Image'], $qcResult->evidenceImageRecords->pluck('image_type')->all());
        $this->assertCount(2, array_filter($qcResult->evidence_images));
        foreach ($qcResult->evidenceImageRecords as $image) {
            Storage::disk('local')->assertExists($image->image_path);
            $this->assertSame($qcResult->id, $image->qc_result_id);
        }
    }

    public function test_accepted_only_qc_line_does_not_require_evidence_images(): void
    {
        $qc = User::factory()->create(['role' => Role::Qc]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'inward',
            'status' => 'grn',
            'invoice_qty' => 10,
            'material' => 'PCM line B',
        ]);

        $this->actingAs($qc);
        Volt::test('qc.queue')
            ->call('openCheck', $gate->id)
            ->set('accepted', '10')
            ->set('qcHold', '0')
            ->set('defective', '0')
            ->set('rejected', '0')
            ->call('submitCheck')
            ->assertHasNoErrors();

        $this->assertSame('qc_done', $gate->fresh()->status);
        $this->assertDatabaseCount('qc_result_evidence_images', 0);
    }

    public function test_non_image_qc_upload_is_rejected_with_a_clear_message(): void
    {
        $qc = User::factory()->create(['role' => Role::Qc]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'inward',
            'status' => 'grn',
            'invoice_qty' => 10,
            'invoice_amount' => 100,
            'rate' => 10,
            'material' => 'PCM line C',
        ]);

        $this->actingAs($qc);
        Volt::test('qc.queue')
            ->call('openCheck', $gate->id)
            ->set('accepted', '8')
            ->set('qcHold', '0')
            ->set('defective', '2')
            ->set('rejected', '0')
            ->set('evidenceImages.0', UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))
            ->set('evidenceImageTypes.0', 'Defective Image')
            ->set('evidenceImageTypes.1', 'Rejected Image')
            ->call('submitCheck')
            ->assertHasErrors(['evidenceImages.0' => 'image'])
            ->assertSee('Choose a valid image file (JPG, PNG, or WebP).');

        $this->assertSame('grn', $gate->fresh()->status);
    }
}
