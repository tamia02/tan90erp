<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Models\Inventory\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\SiteLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Virtual GRN (client meeting 2026-10-13): trading goods the vendor ships
// straight to the customer never reach our gate, but the books still need the
// stock to come in (GRN, vendor payable) and go out (dispatch to the customer).
// This builds the same records a real receipt makes - gate entry "VGRN-...",
// QC marked "not physically inspected", posted GRN - and dispatches the same
// quantities at once, so stock ends where it started.
class VirtualGrnService
{
    public function __construct(
        private GrnPostingService $grn,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array<int, float>  $quantities  PO line id => quantity shipped direct
     */
    public function create(PurchaseOrder $po, array $quantities, string $customer, string $invoiceNumber, ?string $invoiceDate, User $user): GateEntry
    {
        $po->loadMissing('lines');
        if ($po->purchase_nature !== 'Trading') {
            throw ValidationException::withMessages(['virtual' => 'A Virtual GRN is only for Trading POs shipped directly to the customer.']);
        }
        if (! $po->isReleased()) {
            throw ValidationException::withMessages(['virtual' => 'Release the PO first.']);
        }
        if (trim($customer) === '') {
            throw ValidationException::withMessages(['virtualCustomer' => 'Enter the customer the vendor shipped to.']);
        }
        if (trim($invoiceNumber) === '') {
            throw ValidationException::withMessages(['virtualInvoice' => 'Enter the vendor invoice number.']);
        }
        $lines = $po->lines->filter(fn ($l) => (float) ($quantities[$l->id] ?? 0) > 0);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['virtualLines' => 'Enter the quantity shipped.']);
        }
        foreach ($lines as $line) {
            if ((float) $quantities[$line->id] > (float) $line->quantity + 0.0005) {
                throw ValidationException::withMessages(["virtualLines.{$line->id}" => 'More than ordered on this line.']);
            }
        }
        if (GateEntry::withoutSiteScope()->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower(trim($invoiceNumber))])->exists()) {
            throw ValidationException::withMessages(['virtualInvoice' => 'This invoice number is already on a gate entry.']);
        }

        return DB::transaction(function () use ($po, $quantities, $lines, $customer, $invoiceNumber, $invoiceDate, $user) {
            $site = SiteLocation::forUser($user) ?? $po->location_id ?? SiteLocation::defaultId();
            $siteName = $site ? \App\Models\Tan90\MasterData\Location::find($site)?->name : null;
            $amount = app(PurchaseOrderAmountCalculator::class)->amounts($po, $lines->mapWithKeys(fn ($l) => [$l->id => (float) $quantities[$l->id]])->all());
            $productLines = $lines->map(fn ($l) => ['po_line_id' => $l->id, 'sku' => $l->product, 'name' => $l->product, 'qty' => (float) $quantities[$l->id], 'uom' => $l->uom, 'remarks' => 'Virtual GRN'])->values()->all();
            $now = now();

            $gate = GateEntry::create([
                'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => $now,
                'gate_no' => $this->nextNumber(), 'entry_type' => 'inward', 'po_number' => $po->po_number,
                'po_bill_date' => $invoiceDate ?: $now->toDateString(), 'vendor_name' => $po->vendor_name,
                'invoice_number' => trim($invoiceNumber), 'invoice_qty' => (int) round($lines->sum(fn ($l) => (float) $quantities[$l->id])),
                'invoice_amount' => $amount['total'], 'material' => $lines->pluck('product')->implode(' + '), 'product_lines' => $productLines,
                'vehicle_number' => 'DIRECT SHIPMENT', 'driver_name' => '-', 'location' => $siteName ?? 'Direct shipment', 'location_id' => $site,
                'bill_scanned' => true, 'status' => 'grn', 'sla_deadline' => $now,
                'remarks' => 'Virtual GRN - shipped by the vendor directly to '.trim($customer).'; never at our warehouse.',
                // Already "put away" at a virtual bin so the GRN closes the entry straight away.
                'final_bin' => 'DIRECT-SHIP', 'putaway_by' => $user->id, 'putaway_completed_at' => $now,
                // No truck at our gate: never listed under the Guard's "Vehicles Inside".
                'exited_at' => $now,
            ]);

            $qc = QcResult::create([
                'created_by' => $user->id, 'gate_entry_id' => $gate->id, 'sku' => $lines->first()->product,
                'po_qty' => (int) round($lines->sum(fn ($l) => (float) $l->quantity)), 'invoice_qty' => $gate->invoice_qty,
                'physical_received' => $gate->invoice_qty, 'accepted_qty' => $gate->invoice_qty,
                'qc_reasons' => 'Virtual GRN - direct shipment, not physically inspected',
            ]);
            foreach ($lines as $line) {
                $qty = (int) round((float) $quantities[$line->id]);
                $qc->lines()->create([
                    'gate_entry_id' => $gate->id, 'sku' => $line->product, 'po_qty' => (int) round((float) $line->quantity),
                    'invoice_qty' => $qty, 'physical_received' => $qty, 'accepted_qty' => $qty,
                    'unit_price' => $line->list_price, 'suggested_bin' => 'DIRECT-SHIP',
                    'qc_reasons' => 'Virtual GRN - direct shipment, not physically inspected',
                ]);
            }

            $grn = $this->grn->post($gate->fresh(), 'DIRECT-SHIP');

            // ...and straight back out to the customer, at the same site.
            $this->inventory->syncFromMasters();
            $inventory = $site ? $this->inventory->atSite($site) : $this->inventory;
            foreach ($lines as $line) {
                $item = InventoryItem::whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($line->product))])->first()
                    ?? InventoryItem::create(['sku' => trim($line->product), 'item_type' => 'trading', 'unit' => strtoupper((string) $line->uom) ?: 'NOS', 'opening_qty' => 0]);
                $inventory->dispatch($item, (float) round((float) $quantities[$line->id]), trim($customer), $gate->gate_no,
                    'Virtual GRN - direct shipment from '.$po->vendor_name, $user, anyItemType: true);
            }

            AuditLogger::log('Virtual GRN created', "{$gate->gate_no} · {$po->po_number} · {$po->vendor_name} → ".trim($customer), $gate);

            return $gate->fresh(['grnRecord']);
        });
    }

    private function nextNumber(): string
    {
        $prefix = 'VGRN-'.now()->format('Y').'-';
        $last = GateEntry::withoutSiteScope()->where('gate_no', 'like', $prefix.'%')->pluck('gate_no')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }
}
