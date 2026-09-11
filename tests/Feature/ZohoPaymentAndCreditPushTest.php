<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Client asked directly whether Payment Made and Vendor Credits sync to
 * Zoho; they didn't, in either direction — confirmed by inspection, no code
 * referenced either concept at all. Bills already push automatically on
 * every FinanceRecord save (FinanceRecordObserver), and Purchase Receives
 * push on every GRN post, but nothing told Zoho a vendor was actually paid,
 * and nothing pushed a debit note as the Zoho-side Vendor Credit it
 * represents. These tests cover both new push paths.
 */
class ZohoPaymentAndCreditPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
    }

    private function fakeZohoUpsert(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $isGet = $request->method() === 'GET';

            if (str_contains($url, '/contacts')) {
                return $isGet
                    ? Http::response(['contacts' => []], 200)
                    : Http::response(['code' => 0, 'contact' => ['contact_id' => 'zoho-vendor-1']], 200);
            }

            if (str_contains($url, '/bills')) {
                return $isGet
                    ? Http::response(['bills' => [['bill_id' => 'zoho-bill-1', 'bill_number' => 'INV-PAY-1']]], 200)
                    : Http::response(['code' => 0, 'bill' => ['bill_id' => 'zoho-bill-1']], 200);
            }

            if (str_contains($url, '/items')) {
                return $isGet
                    ? Http::response(['items' => []], 200)
                    : Http::response(['code' => 0, 'item' => ['item_id' => 'zoho-item-1']], 200);
            }

            if (str_contains($url, '/vendorpayments') || str_contains($url, '/vendorcredits')) {
                return Http::response(['code' => 0], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_clearing_a_payable_pushes_a_vendor_payment_to_zoho(): void
    {
        $this->fakeZohoUpsert();
        $finance = User::factory()->create(['role' => 'finance']);

        $record = FinanceRecord::create([
            'gate_entry_id' => GateEntry::factory()->create()->id,
            'vendor_name' => 'Payment Test Vendor',
            'invoice_number' => 'INV-PAY-1',
            'rate_per_unit' => 10, 'invoice_value' => 1000, 'accepted_value' => 1000,
            'final_payable' => 1000, 'match_status' => 'matched', 'vendor_status' => 'pending',
        ]);

        $this->actingAs($finance);

        Volt::test('finance.review')->call('setStatus', $record->id, 'cleared')->assertHasNoErrors();

        $this->assertSame('cleared', $record->fresh()->vendor_status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/vendorpayments')
            && ($request['reference_number'] ?? null) === 'INV-PAY-1'
            && (float) ($request['amount'] ?? 0) === 1000.0);
    }

    public function test_clearing_an_already_cleared_payable_does_not_push_a_duplicate_payment(): void
    {
        $this->fakeZohoUpsert();
        $finance = User::factory()->create(['role' => 'finance']);

        $record = FinanceRecord::create([
            'gate_entry_id' => GateEntry::factory()->create()->id,
            'vendor_name' => 'Payment Test Vendor',
            'invoice_number' => 'INV-PAY-2',
            'rate_per_unit' => 10, 'invoice_value' => 1000, 'accepted_value' => 1000,
            'final_payable' => 1000, 'match_status' => 'matched', 'vendor_status' => 'cleared',
        ]);

        $this->actingAs($finance);

        Volt::test('finance.review')->call('setStatus', $record->id, 'cleared');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/vendorpayments'));
    }

    public function test_a_debit_note_pushes_a_vendor_credit_to_zoho(): void
    {
        $this->fakeZohoUpsert();

        $record = FinanceRecord::create([
            'gate_entry_id' => GateEntry::factory()->create()->id,
            'vendor_name' => 'Credit Test Vendor',
            'invoice_number' => 'INV-CREDIT-1',
            'rate_per_unit' => 10, 'invoice_value' => 1000, 'accepted_value' => 950,
            'final_payable' => 950, 'match_status' => 'matched', 'vendor_status' => 'pending',
        ]);

        DebitNote::create([
            'finance_record_id' => $record->id,
            'vendor_name' => 'Credit Test Vendor',
            'reason' => 'Defective goods',
            'amount' => 50,
            'status' => 'issued',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/vendorcredits')
            && ($request['vendor_id'] ?? null) === 'zoho-vendor-1');
    }
}
