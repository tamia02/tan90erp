<?php

namespace App\Services\Outward;

use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\Tan90\MasterData\PackagingType;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\WarehouseStockResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Life of an outward shipment: create (documents decided by the GST tree) ->
// team records Tax Invoice / IRN / E-Way Bill (numbers + files) -> Mark
// Dispatched, which is the only point where stock is deducted.
class OutwardShipmentService
{
    public function __construct(
        private OutwardComplianceService $compliance,
        private OutwardTaxService $taxes,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array{purpose: string, from_location_id?: ?int, from_name: string, from_address?: ?string, from_gstin?: ?string, from_state?: ?string,
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

        $packagingIds = collect($lines)->pluck('packaging_type_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $packagingTypes = $packagingIds->isEmpty() ? collect() : PackagingType::query()
            ->whereIn('id', $packagingIds)
            ->where('status', 'active')
            ->where('approval_status', 'active')
            ->get()
            ->keyBy('id');

        foreach ($lines as $i => $line) {
            $typeId = $line['packaging_type_id'] ?? null;
            $quantity = $line['packaging_quantity'] ?? null;
            $boxSize = $line['box_size'] ?? null;

            if ($typeId === null || $typeId === '') {
                if ($quantity !== null && $quantity !== '' || $boxSize !== null && $boxSize !== '') {
                    throw ValidationException::withMessages(["lines.{$i}.packaging_type_id" => 'Choose a packaging type before entering package quantity or box size.']);
                }
                continue;
            }

            $type = $packagingTypes[(int) $typeId] ?? null;
            if (! $type) {
                throw ValidationException::withMessages(["lines.{$i}.packaging_type_id" => 'Choose an active packaging type from Master Data.']);
            }
            if (! filter_var($quantity, FILTER_VALIDATE_INT) || (int) $quantity < 1) {
                throw ValidationException::withMessages(["lines.{$i}.packaging_quantity" => 'Enter a whole package quantity of at least 1.']);
            }
            if ($type->requires_box_size && ! in_array($boxSize, ['Small', 'Medium', 'Large'], true)) {
                throw ValidationException::withMessages(["lines.{$i}.box_size" => 'Choose Small, Medium, or Large for Box packaging.']);
            }
            if (! $type->requires_box_size && filled($boxSize)) {
                throw ValidationException::withMessages(["lines.{$i}.box_size" => 'Box size is only available for packaging types that require it.']);
            }
        }

        // Fast preflight for useful form feedback. The same check is repeated
        // under item row locks in the transaction below to prevent concurrent
        // users from reserving the same units twice.
        $this->assertStockAvailable($lines, $items, isset($header['from_location_id']) ? (int) $header['from_location_id'] : null);

        $subtotal = round(array_sum(array_map(fn ($l) => (float) $l['qty'] * (float) ($l['rate'] ?? 0), $lines)), 2);
        $decision = $this->compliance->decide($header['purpose'], $header['from_gstin'] ?? null, $header['to_gstin'] ?? null,
            $header['from_state'] ?? null, $header['to_state'] ?? null, $subtotal);
        $tax = $this->taxes->calculate(
            $lines,
            $items,
            $decision['document_type'] !== 'delivery_challan',
            $header['from_gstin'] ?? null,
            $header['to_gstin'] ?? null,
            $header['from_state'] ?? null,
            $header['to_state'] ?? null,
            requireStates: true,
        );
        $total = $tax['total_value'];
        $decision = $this->compliance->decide($header['purpose'], $header['from_gstin'] ?? null, $header['to_gstin'] ?? null,
            $header['from_state'] ?? null, $header['to_state'] ?? null, $total);

        return DB::transaction(function () use ($header, $tax, $lines, $items, $user, $total, $decision) {
            $itemIds = collect($lines)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $lockedItems = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertStockAvailable($lines, $lockedItems, isset($header['from_location_id']) ? (int) $header['from_location_id'] : null);

            $shipment = OutwardShipment::create(array_merge($header, [
                'number' => 'TMP-'.Str::uuid(),
                'from_gstin' => $this->clean($header['from_gstin'] ?? null),
                'to_gstin' => $this->clean($header['to_gstin'] ?? null),
                'subtotal_value' => $tax['subtotal_value'],
                'tax_total' => $tax['tax_total'],
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

            foreach ($tax['lines'] as $index => $line) {
                $packaging = $lines[$index] ?? [];
                $shipment->lines()->create([
                    ...$line,
                    'warehouse_id' => WarehouseStockResolver::forItemAtSite(isset($header['from_location_id']) ? (int) $header['from_location_id'] : null, $items->get((int) $line['item_id'])),
                    'packaging_type_id' => filled($packaging['packaging_type_id'] ?? null) ? (int) $packaging['packaging_type_id'] : null,
                    'packaging_quantity' => filled($packaging['packaging_quantity'] ?? null) ? (int) $packaging['packaging_quantity'] : null,
                    'box_size' => filled($packaging['box_size'] ?? null) ? $packaging['box_size'] : null,
                ]);
            }

            app(\App\Services\OperationalEventMailer::class)->notify(
                "Outward shipment {$shipment->number} needs review",
                "{$user->name} created outward shipment {$shipment->number} for {$shipment->to_name}. Status: {$shipment->status}.",
                $shipment->from_location_id ? (int) $shipment->from_location_id : null,
                [\App\Enums\Role::StoreManager],
                '/inventory/dispatch',
            );

            return $shipment->load('lines.item', 'lines.packagingType');
        });
    }

    /** Preview the item-specific GST split without requiring incomplete form states. */
    public function taxPreview(array $lines, Collection $items, bool $taxable, ?string $fromGstin, ?string $toGstin, ?string $fromState, ?string $toState): array
    {
        return $this->taxes->calculate($lines, $items, $taxable, $fromGstin, $toGstin, $fromState, $toState);
    }

    /**
     * Save the documents the team created on the government portals.
     *
     * @param  array<string, mixed>  $fields  invoice_number, invoice_date, irn, irn_ack_number, irn_ack_date, ewb_number, ewb_date, ewb_valid_until, vehicle_number, transporter
     * @param  array<string, ?UploadedFile>  $files  invoice_file, irn_file, ewb_file
     */
    public function saveDocuments(OutwardShipment $shipment, array $fields, array $files): OutwardShipment
    {
        $previousStatus = $shipment->status;
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

        if ($previousStatus === 'documents_pending' && $shipment->status === 'ready') {
            app(\App\Services\OperationalEventMailer::class)->notify(
                "Documents complete for outward shipment {$shipment->number}",
                "All required documents are now available for {$shipment->number} to {$shipment->to_name}. The Guard can receive its truck at the gate.",
                $shipment->from_location_id ? (int) $shipment->from_location_id : null,
                [\App\Enums\Role::StoreManager, \App\Enums\Role::Guard],
                '/inventory/dispatch',
            );
        }

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
                // Stock leaves the site the shipment goes out from.
                $inventory = $shipment->from_location_id ? $this->inventory->atSite((int) $shipment->from_location_id) : $this->inventory;
                foreach ($shipment->lines()->with('item')->get() as $line) {
                    $lineInventory = $line->warehouse_id ? $this->inventory->atWarehouse((int) $line->warehouse_id) : $inventory;
                    $dispatch = $lineInventory->dispatch($line->item, (float) $line->qty, $shipment->to_name, $shipment->number,
                        $shipment->purposeLabel().($shipment->challan_number ? " · {$shipment->challan_number}" : '').($shipment->invoice_number ? " · Invoice {$shipment->invoice_number}" : ''),
                        $user, anyItemType: $shipment->purpose !== 'sales', exceptReservedShipmentId: $shipment->id);
                    $line->update(['dispatch_id' => $dispatch->id]);
                }
                $shipment->update(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatched_by' => $user->id]);

                app(\App\Services\OperationalEventMailer::class)->notify(
                    "Outward shipment {$shipment->number} dispatched",
                    "{$shipment->number} has left the premises for {$shipment->to_name}.",
                    $shipment->from_location_id ? (int) $shipment->from_location_id : null,
                    [\App\Enums\Role::StoreManager, \App\Enums\Role::Finance],
                    '/inventory/dispatch',
                );

                return $shipment;
            });
        } catch (ValidationException $e) {
            // Stock errors come back keyed 'qty'/'itemId'; show them on the shipment.
            throw ValidationException::withMessages(['dispatch' => "{$shipment->number} not dispatched - ".collect($e->errors())->flatten()->implode(' ')]);
        }
    }

    /**
     * Stock per item: in stock now, already promised to open (not yet
     * dispatched) shipments, and free = what a new shipment may still take.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array{in_stock: float, reserved: float, free: float}>
     */
    // $fromSite: stock and open shipments of that site only (null = as the person sees it).
    public function stockPicture(array $itemIds, ?int $exceptShipmentId = null, ?int $fromSite = null): array
    {
        $items = InventoryItem::whereIn('id', array_unique($itemIds))->get();
        $picture = [];
        foreach ($items as $item) {
            $warehouseId = $fromSite ? WarehouseStockResolver::forItemAtSite($fromSite, $item) : null;
            $inventory = $warehouseId ? $this->inventory->atWarehouse($warehouseId) : ($fromSite ? $this->inventory->atSite($fromSite) : $this->inventory);
            $balances = $inventory->balances(collect([$item]));
            $reserved = $inventory->reservedForOpenShipments(collect([$item]), $fromSite, $exceptShipmentId, $warehouseId);
            $inStock = (float) ($balances[$item->id]['final'] ?? 0);
            $held = round((float) ($reserved[$item->id] ?? 0), 3);
            $picture[$item->id] = ['in_stock' => $inStock, 'reserved' => $held, 'free' => round(max(0, $inStock - $held), 3)];
        }

        return $picture;
    }

    /** Ensure requested quantities fit after counting all current open-shipment reservations. */
    private function assertStockAvailable(array $lines, Collection $items, ?int $fromSite): void
    {
        $stock = $this->stockPicture(array_column($lines, 'item_id'), null, $fromSite);
        $wanted = [];
        foreach ($lines as $i => $line) {
            $itemId = (int) $line['item_id'];
            $wanted[$itemId] = ($wanted[$itemId] ?? 0) + (float) $line['qty'];
            $free = (float) ($stock[$itemId]['free'] ?? 0);
            if ($wanted[$itemId] > $free + 0.0005) {
                $item = $items->get($itemId);
                $reserved = (float) ($stock[$itemId]['reserved'] ?? 0);
                $unit = $item?->unit ?? 'units';
                $name = $item?->displayName() ?? 'this item';
                $location = $fromSite ? ' at '.\App\Support\SiteLocation::nameForId($fromSite) : '';
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "Only {$this->qty($free)} {$unit} of {$name} free{$location}"
                    .($reserved > 0 ? " ({$this->qty((float) ($stock[$itemId]['in_stock'] ?? 0))} in stock, {$this->qty($reserved)} already on other open shipments)" : '')
                    .' - make or receive more first.']);
            }
        }
    }

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') ?: '0';
    }

    // Shipments a truck can be linked to: not dispatched or cancelled, no gate
    // pass yet. Documents may still be pending - the E-Way Bill usually needs
    // the truck number - but the truck can't leave until they are complete.
    public function openForGate()
    {
        return OutwardShipment::with('lines.item')->whereIn('status', ['documents_pending', 'ready'])->whereNull('gate_entry_id')->latest('id')->get();
    }

    // Truck <-> shipment. The truck's number and transporter fill the
    // shipment's (needed for the E-Way Bill) when empty.
    public function linkGate(OutwardShipment $shipment, \App\Models\GateEntry $gate): void
    {
        if (! in_array($shipment->status, ['documents_pending', 'ready'], true) || ($shipment->gate_entry_id && $shipment->gate_entry_id !== $gate->id)) {
            throw ValidationException::withMessages(['outwardShipmentId' => "{$shipment->number} already has a truck or is no longer open."]);
        }
        DB::transaction(function () use ($shipment, $gate) {
            $gate->update(['outward_shipment_id' => $shipment->id]);
            $shipment->update([
                'gate_entry_id' => $gate->id,
                'vehicle_number' => $shipment->vehicle_number ?: ($gate->vehicle_number !== 'WALK-IN' ? $gate->vehicle_number : null),
                'transporter' => $shipment->transporter ?: $gate->transporter,
            ]);
        });
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
