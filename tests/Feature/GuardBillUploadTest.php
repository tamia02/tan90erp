<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Reproduces the reported bug: the guard "Camera / Upload" button used
 * wire:click on a bare <input type="file">, which fires the moment the file
 * picker opens (not once a file is actually chosen) and never stored the
 * file anywhere — no WithFileUploads trait, no bound property, nothing
 * persisted on the GateEntry. Not RefreshDatabase, matching
 * LegacyRolePagesLoadTest's pattern for this same legacy area — but unlike
 * that test, this creates its own throwaway guard user via the factory
 * rather than depending on the persistent demo seed's guard@tan90.test:
 * this class runs alongside three new RefreshDatabase-based Zoho sync tests
 * in the same filter set, and whichever of those runs first triggers
 * RefreshDatabase's migrate:fresh (no --seed) once per process, wiping the
 * demo seed out from under this test depending on execution order.
 */
class GuardBillUploadTest extends TestCase
{
    public function test_uploading_a_bill_file_marks_it_scanned_and_persists_the_document_path(): void
    {
        // Livewire's FileUploadConfiguration::disk() hardcodes the
        // 'tmp-for-tests' disk whenever app()->runningUnitTests() is true —
        // that's where TemporaryUploadedFile::store() actually writes here,
        // not config('filesystems.default').
        Storage::fake('tmp-for-tests');

        $guard = User::factory()->create(['role' => Role::Guard]);
        $this->actingAs($guard);

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('billFile', $file)
            ->assertHasNoErrors('billFile')
            ->assertSet('billScanned', true)
            ->set('driverName', 'Test Driver')
            ->set('driverPhone', '+91 90000 00000')
            ->set('vehicleNumber', 'MH 04 GT 1234')
            ->set('invoiceNumber', 'GBU-TEST-'.now()->format('His'))
            ->set('invoiceAmount', '1000')
            ->set('poNumber', 'GBU-PO-TEST')
            ->set('vendorName', 'Guard Upload Test Vendor')
            ->set('material', 'Test material')
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('po_number', 'GBU-PO-TEST')->latest('id')->first();
        $this->assertNotNull($gate);
        $this->assertTrue($gate->bill_scanned);
        $this->assertNotNull($gate->bill_document_path);
        Storage::disk('tmp-for-tests')->assertExists($gate->bill_document_path);

        $gate->delete();
        $guard->delete();
    }

    public function test_the_upload_button_no_longer_flips_bill_scanned_before_a_file_is_chosen(): void
    {
        $guard = User::factory()->create(['role' => Role::Guard]);
        $this->actingAs($guard);

        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->assertSet('billScanned', false)
            ->assertSet('billFile', null);

        $guard->delete();
    }
}
