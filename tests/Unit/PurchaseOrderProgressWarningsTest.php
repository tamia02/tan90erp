<?php

namespace Tests\Unit;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\VendorSubmission;
use App\Services\PurchaseOrderProgressService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PurchaseOrderProgressWarningsTest extends TestCase
{
    public function test_fully_billed_split_po_exposes_invoice_amount_rounding_variance_without_rewriting_bills(): void
    {
        $po = PurchaseOrder::make(['reverse_charge' => false]);
        $po->setRelation('lines', new Collection([
            PurchaseOrderLine::make(['product' => 'TNPM500-10', 'quantity' => 47850, 'list_price' => 13.50, 'tax' => 116275.50]),
        ]));
        $submissions = new Collection([
            VendorSubmission::make(['invoice_qty' => 6765, 'invoice_amount' => 107766.00]),
            VendorSubmission::make(['invoice_qty' => 9735, 'invoice_amount' => 155079.00]),
            VendorSubmission::make(['invoice_qty' => 21120, 'invoice_amount' => 336442.00]),
            VendorSubmission::make(['invoice_qty' => 10230, 'invoice_amount' => 162963.90]),
        ]);

        $review = app(PurchaseOrderProgressService::class)->invoiceAmountReview($po, $submissions, 47850, 47850);

        $this->assertSame(762250.50, $review['po_total_amount']);
        $this->assertSame(0.40, $review['invoice_amount_variance']);
        $this->assertTrue($review['invoice_amount_review_required']);
        $this->assertSame(762250.90, (float) $submissions->sum('invoice_amount'), 'Review must not rewrite historical supplier invoices.');

        $partialReview = app(PurchaseOrderProgressService::class)->invoiceAmountReview($po, $submissions, 47850, 40000);
        $this->assertFalse($partialReview['invoice_amount_review_required'], 'Do not assess total invoice values until the full PO quantity has been billed.');
    }

    public function test_it_flags_over_po_gate_quantity_and_repeated_invoice_references_without_dropping_history(): void
    {
        $gates = new Collection([
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'SPINV2345', 'invoice_qty' => 4000, 'status' => 'closed', 'gate_no' => 'GP-001']),
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'SPINV2345', 'invoice_qty' => 4000, 'status' => 'pending_validation', 'gate_no' => 'GP-002']),
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'spinv2345', 'invoice_qty' => 4000, 'status' => 'pending_validation', 'gate_no' => 'GP-003']),
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'SPINV2345', 'invoice_qty' => 4000, 'status' => 'pending_validation', 'gate_no' => 'GP-004']),
            // Outward quantities must not inflate inward PO receipt warnings.
            GateEntry::make(['entry_type' => 'outward', 'invoice_number' => 'OUT-1', 'invoice_qty' => 9000, 'status' => 'pending_validation', 'gate_no' => 'GP-005']),
        ]);

        $warnings = app(PurchaseOrderProgressService::class)->gateWarnings($gates, 15000);

        $this->assertSame(1000.0, $warnings['gate_overage_qty']);
        $this->assertSame(3, $warnings['pending_validation_count']);
        $this->assertCount(1, $warnings['repeated_invoices']);
        $this->assertSame(4, $warnings['repeated_invoices'][0]['gate_count']);
        $this->assertSame(3, $warnings['repeated_invoices'][0]['pending_count']);
        $this->assertSame(['GP-001', 'GP-002', 'GP-003', 'GP-004'], $warnings['repeated_invoices'][0]['gate_numbers']);
    }

    public function test_multiple_distinct_partial_invoices_within_po_quantity_are_not_flagged(): void
    {
        $gates = new Collection([
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'INV-1', 'invoice_qty' => 6000, 'status' => 'closed']),
            GateEntry::make(['entry_type' => 'inward', 'invoice_number' => 'INV-2', 'invoice_qty' => 4000, 'status' => 'validated']),
        ]);

        $warnings = app(PurchaseOrderProgressService::class)->gateWarnings($gates, 15000);

        $this->assertSame(0.0, $warnings['gate_overage_qty']);
        $this->assertSame(0, $warnings['pending_validation_count']);
        $this->assertSame([], $warnings['repeated_invoices']);
    }
}
