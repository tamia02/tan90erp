<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorSubmission;
use App\Services\VendorBillDocumentService;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Uses the persistent Sharp Polymer demo fixture so the test validates the
 * real PO-linked bill shape without creating a second vendor document.
 */
class VendorBillPdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $compiled = storage_path('framework/test-views/vendor-bill');
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config()->set('view.compiled', $compiled);
    }

    public function test_vendor_can_download_and_share_a_generated_bill_pdf(): void
    {
        $submission = VendorSubmission::firstOrFail();
        $vendor = User::where('name', $submission->vendor_name)->firstOrFail();

        $this->actingAs($vendor);

        Volt::test('vendor.submissions')
            ->assertSee('Bill PDF')
            ->assertSee('Share');

        $download = $this->get(route('vendor.submission.pdf', $submission));
        $download->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $download->streamedContent());

        $data = app(VendorBillDocumentService::class)->data($submission);
        $this->assertNotEmpty($data['shareUrl']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['qrDataUri']);

        auth()->logout();
        $shared = $this->get($data['shareUrl']);
        $shared->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $shared->getContent());
    }

    public function test_another_vendor_cannot_download_the_bill(): void
    {
        $submission = VendorSubmission::firstOrFail();
        $otherVendor = User::where('role', 'vendor')
            ->where('name', '!=', $submission->vendor_name)
            ->first();

        if (! $otherVendor) {
            $this->markTestSkipped('No second seeded vendor is available.');
        }

        $this->actingAs($otherVendor);

        $this->get(route('vendor.submission.pdf', $submission))->assertForbidden();
    }
}
