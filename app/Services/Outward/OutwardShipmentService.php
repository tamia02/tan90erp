<?php

namespace App\Services\Outward;

use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Life of an outward shipment: create (documents decided by the GST tree) ->
// team records Tax Invoice / IRN / E-Way Bill (numbers + files) -> Mark
// Dispatched, which is the only point where stock is deducted.
class OutwardShipmentService
{
    public function __construct(private OutwardComplianceService $compliance, private InventoryService $inventory) {}

    /**
     * @param  array{purpose: string, from_location_id?: ?int, from_name: string, from_gstin?: ?string, from_state?: ?string,
     *     customer_id?: ?int, to_location_id?: ?int, to_name: string, to_gstin?: ?string, to_state?: ?string, to_address?: ?string,
     *     sales_order_number?: ?string, vehicle_number?: ?string, transporter?: ?string, notes?: ?string}  $header
     * @param  array<int, array{item_id: int, qty: float|string, rate: float|string}>  $lines
     */
    public function create(array $header, array $lines, User $user): OutwardShipment
    {
        if (! isset(OutwardShipment::PURPOSES[$header['purpose'] ?? ''])) {
            throw ValidationException::withMessages(['purpose' => 'Choose why the goods are going out.']);
        }
        $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item with a quantity.']);
        }

        $items = InventoryItem::whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
        foreach ($lines as $i => $line) {
            $item = $items[$line['item_id']] ?? null;
            if (! $item) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => 'Unknown item.']);
            }
            if ($header['purpose'] === 'sales' && ! in_array($item->item_type, InventoryItem::SELLABLE_TYPES, true)) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->displayName()} is {$item->typeLabel()} - only Finished Goods and Trading Goods can be sold."]);
            }
        }

        $total = round(array_sum(array_map(fn ($l) => (float) $l['qty'] * (float) ($l['rate'] ?? 0), $lines)), 2);
        $decision = $this->compliance->decide($header['purpose'], $header['from_gstin'] ?? null, $header['to_gstin'] ?? null,
            $header['from_state'] ?? null, $header['to_state'] ?? null, $total);

        return DB::transaction(function () use ($header, $lines, $user, $total, $decision) {
            $shipment = OutwardShipment::create(array_merge($header, [
                'number' => 'TMP-'.Str::uuid(),
                'from_gstin' => $this->clean($header['from_gstin'] ?? null),
                'to_gstin' => $this->clean($header['to_gstin'] ?? null),
                'total_value' => $total,
                'document_type' => $decision['document_type'],
                'is_b2b' => $decision['is_b2b'],
                'requires_irn' => $decision['requires_irn'],
                'requires_ewb' => $decision['requires_ewb'],
                'decision_steps' => $decision['steps'],
                'created_by' => $user->id,
            ]));
            $shipment->number = sprintf('OUT-%05d', $shipment->id);
            if ($decision['document_type'] === 'delivery_challan') {
                $shipment->challan_number = sprintf('DC-%05d', $shipment->id);
            }
            $shipment->status = $shipment->missingDocuments() ? 'documents_pending' : 'ready';
            $shipment->save();

            foreach ($lines as $line) {
                $shipment->lines()->create([
                    'item_id' => (int) $line['item_id'], 'qty' => (float) $line['qty'], 'rate' => (float) ($line['rate'] ?? 0),
                    'value' => round((float) $line['qty'] * (float) ($line['rate'] ?? 0), 2),
                ]);
            }

            return $shipment->load('lines.item');
        });
    }

    /**
     * Save the documents the team created on the government portals.
     *
     * @param  array<string, mixed>  $fields  invoice_number, invoice_date, irn, irn_ack_number, irn_ack_date, ewb_number, ewb_date, ewb_valid_until, vehicle_number, transporter
     * @param  array<string, ?UploadedFile>  $files  invoice_file, irn_file, ewb_file
     */
    public function saveDocuments(OutwardShipment $shipment, array $fields, array $files): OutwardShipment
    {
        if ($shipment->status === 'dispatched' || $shipment->status === 'cancelled') {
            throw ValidationException::withMessages(['documents' => "{$shipment->number} is {$shipment->status} - documents can no longer be changed."]);
        }

        $allowed = ['invoice_number', 'invoice_date', 'irn', 'irn_ack_number', 'irn_ack_date', 'ewb_number', 'ewb_date', 'ewb_valid_until', 'vehicle_number', 'transporter'];
        $shipment->fill(array_map(fn ($v) => $v === '' ? null : $v, array_intersect_key($fields, array_flip($allowed))));
        foreach (['invoice_file', 'irn_file', 'ewb_file'] as $key) {
            if (($files[$key] ?? null) instanceof UploadedFile) {
                $shipment->{$key} = $files[$key]->store('outward-documents', 'local');
            }
        }
        $shipment->status = $shipment->missingDocuments() ? 'documents_pending' : 'ready';
        $shipment->save();

        return $shipment;
    }

    public function markDispatched(OutwardShipment $shipment, User $user): OutwardShipment
    {
        if ($shipment->status === 'dispatched') {
            return $shipment;
        }
        if ($missing = $shipment->missingDocuments()) {
            throw ValidationException::withMessages(['dispatch' => 'Cannot dispatch yet - missing: '.implode(', ', $missing).'.']);
        }
        if ($shipment->status === 'cancelled') {
            throw ValidationException::withMessages(['dispatch' => "{$shipment->number} was cancelled."]);
        }

        try {
            return DB::transaction(function () use ($shipment, $user) {
                // All lines leave together, or none do (any stock shortage rolls back).
                foreach ($shipment->lines()->with('item')->get() as $line) {
                    $dispatch = $this->inventory->dispatch($line->item, (float) $line->qty, $shipment->to_name, $shipment->number,
                        $shipment->purposeLabel().($shipment->challan_number ? " · {$shipment->challan_number}" : '').($shipment->invoice_number ? " · Invoice {$shipment->invoice_number}" : ''),
                        $user, anyItemType: $shipment->purpose !== 'sales');
                    $line->update(['dispatch_id' => $dispatch->id]);
                }
                $shipment->update(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatched_by' => $user->id]);

                return $shipment;
            });
        } catch (ValidationException $e) {
            // Stock errors come back keyed 'qty'/'itemId'; show them on the shipment.
            throw ValidationException::withMessages(['dispatch' => "{$shipment->number} not dispatched - ".collect($e->errors())->flatten()->implode(' ')]);
        }
    }

    // Shipments the Guard can make an outward gate pass for: documents
    // complete, not dispatched, no gate pass yet.
    public function readyForGate()
    {
        return OutwardShipment::with('lines.item')->where('status', 'ready')->whereNull('gate_entry_id')->latest('id')->get();
    }

    public function cancel(OutwardShipment $shipment): void
    {
        if ($shipment->status === 'dispatched') {
            throw ValidationException::withMessages(['cancel' => 'A dispatched shipment cannot be cancelled.']);
        }
        if ($shipment->gate_entry_id) {
            throw ValidationException::withMessages(['cancel' => "{$shipment->number} is on gate pass {$shipment->gateEntry?->gate_no} - the truck is already at the gate."]);
        }
        $shipment->update(['status' => 'cancelled']);
    }

    private function clean(?string $gstin): ?string
    {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) $gstin));

        return $gstin !== '' ? $gstin : null;
    }
}
