<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InventoryDispatch;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryProduction;
use App\Models\Outward\OutwardShipmentLine;
use App\Models\LedgerEntry;
use App\Models\SkuMaster;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Final = Opening + Received (GRN, accepted) + Produced + Adjusted - Consumed - Wastage - Dispatched.
// "Received" is read from the GRN ledger (bucket 'available', same SKU text)
// and never written here, so the vendor -> GRN flow stays exactly as it is.
class InventoryService
{
    // Stock per site (2026-10-12). A person tied to a site sees and moves that
    // site's stock. Super Admin / people with no site see all sites added up
    // (the same numbers as before) and their stock changes go to the chosen
    // site, Bhiwandi (the main warehouse) unless they pick another.
    private ?int $site = null;

    private bool $siteChosen = false;

    private ?int $warehouse = null;

    private bool $warehouseChosen = false;

    /** A copy working at one site (null = all sites added up, for reading). */
    public function atSite(?int $site): static
    {
        $copy = clone $this;
        $copy->site = $site;
        $copy->siteChosen = true;

        return $copy;
    }

    /** A copy scoped to one physical warehouse, within its parent site. */
    public function atWarehouse(int $warehouseId, bool $includeArchived = false): static
    {
        $warehouseQuery = \App\Models\Tan90\MasterData\Warehouse::query();
        if ($includeArchived) {
            $warehouseQuery->withTrashed();
        }
        $warehouse = $warehouseQuery->findOrFail($warehouseId);
        $copy = clone $this;
        $copy->site = (int) $warehouse->tan90_location_id;
        $copy->siteChosen = true;
        $copy->warehouse = (int) $warehouse->id;
        $copy->warehouseChosen = true;

        return $copy;
    }

    /** Site whose stock is shown: the chosen one, else the person's site, else all (null). */
    public function readSite(): ?int
    {
        return $this->siteChosen ? $this->site : \App\Support\SiteLocation::current();
    }

    /** Site a stock change is booked to: never "all". */
    public function writeSite(): ?int
    {
        return $this->readSite() ?? \App\Support\SiteLocation::defaultId();
    }

    private function readWarehouse(): ?int
    {
        return $this->warehouseChosen ? $this->warehouse : null;
    }

    private function writeWarehouse(?int $itemId = null): ?int
    {
        if ($this->warehouseChosen) {
            return $this->warehouse;
        }
        $item = $itemId ? InventoryItem::find($itemId) : null;

        return WarehouseStockResolver::forItemAtSite($this->writeSite(), $item);
    }

    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return array<int, array{opening: float, received: float, produced: float, adjusted: float, consumed: float, wastage: float, dispatched: float, final: float}>
     */
    public function balances(Collection $items): array
    {
        return $this->balancesAt($items, $this->readSite(), $this->readWarehouse());
    }

    /** Quantities committed to shipments created but not yet dispatched. */
    public function reservedForOpenShipments(Collection $items, ?int $site = null, ?int $exceptShipmentId = null, ?int $warehouseId = null): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        return OutwardShipmentLine::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereHas('shipment', fn ($query) => $query
                ->whereIn('status', ['documents_pending', 'ready'])
                ->when($site !== null, fn ($query) => $query->where('from_location_id', $site))
                ->when($exceptShipmentId !== null, fn ($query) => $query->whereKeyNot($exceptShipmentId)))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty) as total')
            ->pluck('total', 'item_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    // $site null = every site added up. Rows recorded before stock had a site
    // (no site on the movement / GRN gate entry) count at the main site.
    private function balancesAt(Collection $items, ?int $site, ?int $warehouseId = null): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $main = $site !== null && $site === \App\Support\SiteLocation::defaultId();
        $atSite = fn ($q, string $column) => $site === null ? $q : $q->where(fn ($w) => $main ? $w->where($column, $site)->orWhereNull($column) : $w->where($column, $site));

        $receivedQuery = LedgerEntry::query()
            ->where('ledger_entries.bucket', 'available')
            ->whereIn('ledger_entries.sku', $items->pluck('sku'))
            ->when($warehouseId !== null, fn ($query) => $query->where('ledger_entries.warehouse_id', $warehouseId));
        if ($site !== null) {
            $tokens = \App\Support\SiteLocation::nameTokens($site);
            $receivedQuery->leftJoin('gate_entries as site_gate', 'site_gate.id', '=', 'ledger_entries.gate_entry_id')
                ->where(function ($scope) use ($site, $main, $tokens): void {
                    $scope->where('site_gate.location_id', $site)
                        ->orWhere(function ($legacy) use ($site, $main, $tokens): void {
                            $legacy->whereNull('site_gate.location_id')->where(function ($name) use ($site, $main, $tokens): void {
                                foreach ($tokens as $token) {
                                    $name->orWhere('site_gate.location', 'like', '%'.$token.'%');
                                }
                                if ($main) {
                                    $name->orWhereNull('site_gate.location')->orWhere('site_gate.location', '');
                                }
                            });
                        });
                });
        }
        $received = $receivedQuery
            ->groupBy('ledger_entries.sku')
            ->selectRaw('ledger_entries.sku as sku, SUM(ledger_entries.qty) as total')
            ->pluck('total', 'sku');

        $moved = $atSite(InventoryMovement::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId)), 'location_id')
            ->groupBy('item_id', 'type')
            ->selectRaw('item_id, type, SUM(qty) as total')
            ->get()
            ->groupBy('item_id');

        $balances = [];
        foreach ($items as $item) {
            $byType = ($moved->get($item->id) ?? collect())->pluck('total', 'type');
            $row = [
                // Opening stock has no site: it belongs to the main site (and the all-sites total).
                'opening' => ($site === null || ($main && ($warehouseId === null || WarehouseStockResolver::forTypeAtSite($site, $item->item_type) === $warehouseId))) ? (float) $item->opening_qty : 0.0,
                'received' => (float) ($received[$item->sku] ?? 0),
                'produced' => (float) ($byType['produced'] ?? 0),
                'consumed' => (float) ($byType['consumed'] ?? 0),
                'wastage' => (float) ($byType['wastage'] ?? 0),
                'dispatched' => (float) ($byType['dispatched'] ?? 0),
                'adjusted' => round((float) ($byType['adjust_in'] ?? 0) - (float) ($byType['adjust_out'] ?? 0), 3),
            ];
            $row['final'] = round($row['opening'] + $row['received'] + $row['produced'] + $row['adjusted'] - $row['consumed'] - $row['wastage'] - $row['dispatched'], 3);
            $balances[$item->id] = $row;
        }

        return $balances;
    }

    /**
     * Every SKU Master item, and every SKU that has come in through GRN, shows
     * up in Inventory automatically - nobody re-creates items here. Reads
     * sku_masters / ledger_entries only (never writes them, so no Zoho push).
     * Type is a best guess from the SKU Master category and can be changed.
     */
    public function syncFromMasters(): int
    {
        $existing = InventoryItem::pluck('sku')->map(fn ($sku) => mb_strtolower($sku))->flip();
        $created = 0;

        $candidates = SkuMaster::get(['sku', 'category', 'unit', 'product_code'])
            ->map(fn ($sku) => ['sku' => $sku->sku, 'category' => $sku->category, 'unit' => $sku->unit, 'code' => $sku->product_code])
            ->concat(LedgerEntry::distinct()->pluck('sku')->map(fn ($sku) => ['sku' => $sku, 'category' => null, 'unit' => null, 'code' => null]));

        foreach ($candidates as $candidate) {
            $sku = trim((string) $candidate['sku']);
            if ($sku === '') {
                continue;
            }
            if ($existing->has(mb_strtolower($sku))) {
                // Keep the internal SKU private for scoped users, but expose its
                // stable master-data product code when the inventory item lacks one.
                $code = trim((string) ($candidate['code'] ?? ''));
                if ($code !== '') {
                    InventoryItem::query()->where('sku', $sku)
                        ->where(fn ($query) => $query->whereNull('code')->orWhere('code', ''))
                        ->update(['code' => $code]);
                }

                continue;
            }
            try {
                InventoryItem::create([
                    'sku' => $sku,
                    'code' => trim((string) ($candidate['code'] ?? '')) ?: null,
                    'item_type' => self::guessType($candidate['category']),
                    'unit' => strtoupper(trim((string) $candidate['unit'])) ?: 'NOS',
                    'opening_qty' => 0,
                ]);
            } catch (UniqueConstraintViolationException) {
                // MySQL's collation treats some names as equal that PHP doesn't
                // (accents, trailing spaces) - the item already exists, skip it.
                $existing->put(mb_strtolower($sku), true);

                continue;
            }
            $existing->put(mb_strtolower($sku), true);
            $created++;
        }

        return $created;
    }

    public static function guessType(?string $category): string
    {
        $category = mb_strtolower((string) $category);

        return match (true) {
            str_contains($category, 'pack') || preg_match('/\bpm\b/', $category) === 1 => 'pm',
            str_contains($category, 'finish') || preg_match('/\bfg\b/', $category) === 1 => 'fg',
            str_contains($category, 'trad') => 'trading',
            default => 'rm',
        };
    }

    /**
     * Every stock movement of one item, newest first: received through GRN,
     * produced, used in production, wasted, dispatched.
     *
     * @return Collection<int, array{at: mixed, label: string, qty: float, kind: string}>
     */
    public function history(InventoryItem $item): Collection
    {
        $site = $this->readSite();
        $warehouseId = $this->readWarehouse();
        $main = $site !== null && $site === \App\Support\SiteLocation::defaultId();
        $grn = LedgerEntry::with('gateEntry')->where('sku', $item->sku)->where('bucket', 'available')
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))->get()
            ->filter(fn ($entry) => $site === null
                || $entry->gateEntry?->location_id === $site
                || ($entry->gateEntry?->location_id === null && \App\Support\SiteLocation::matchesName($entry->gateEntry?->location, $site))
                || ($main && $entry->gateEntry?->location_id === null && blank($entry->gateEntry?->location)))
            ->map(fn ($entry) => ['at' => $entry->created_at, 'label' => trim('Received (GRN) · '.($entry->gateEntry?->gate_no ?? '').' '.($entry->gateEntry?->po_number ?? '')), 'qty' => (float) $entry->qty, 'kind' => 'received', 'location_id' => $entry->gateEntry?->location_id, 'warehouse_id' => $entry->warehouse_id]);
        $moves = InventoryMovement::with(['production', 'dispatch', 'creator'])->where('item_id', $item->id)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($site !== null, fn ($q) => $q->where(fn ($w) => $main ? $w->where('location_id', $site)->orWhereNull('location_id') : $w->where('location_id', $site)))->get()
            ->map(fn ($m) => [
                'at' => $m->created_at,
                'label' => match ($m->type) {
                    'produced' => 'Produced (Create Bundle) · '.$m->production?->number,
                    'consumed' => 'Used in production · '.$m->production?->number,
                    'wastage' => 'Wastage · '.$m->production?->number,
                    'adjust_in' => 'Stock adjusted up by '.($m->creator?->name ?? 'Store Manager').($m->note ? ' · '.$m->note : ''),
                    'adjust_out' => 'Stock adjusted down by '.($m->creator?->name ?? 'Store Manager').($m->note ? ' · '.$m->note : ''),
                    'dispatched' => 'Dispatched · '.trim(($m->dispatch?->reference ? $m->dispatch->reference.' · ' : '').$m->dispatch?->number).' → '.$m->dispatch?->customer_name,
                    default => ucfirst($m->type),
                },
                'qty' => in_array($m->type, InventoryMovement::IN_TYPES, true) ? (float) $m->qty : -(float) $m->qty,
                'kind' => in_array($m->type, ['adjust_in', 'adjust_out'], true) ? 'adjusted' : $m->type,
                'location_id' => $m->location_id,
                'warehouse_id' => $m->warehouse_id,
            ]);

        return $grn->concat($moves)->sortByDesc('at')->values();
    }

    public function available(InventoryItem $item): float
    {
        return $this->balances(collect([$item]))[$item->id]['final'];
    }

    /** Quantities reserved for mapped Forge work orders at this exact warehouse. */
    private function reservedForForge(int $itemId, ?int $siteId, ?int $warehouseId): float
    {
        return (float) DB::table('forge_material_reservations')
            ->join('forge_work_orders', 'forge_work_orders.id', '=', 'forge_material_reservations.work_order_id')
            ->where('forge_material_reservations.inventory_item_id', $itemId)
            ->where('forge_work_orders.status', 'material_reserved')
            ->when($siteId !== null, fn ($q) => $q->where('forge_work_orders.site_id', $siteId))
            ->when($warehouseId !== null, fn ($q) => $q->where('forge_work_orders.rm_warehouse_id', $warehouseId))
            ->sum('forge_material_reservations.qty');
    }

    /** Stock at the site a change would be booked to. */
    private function availableToMove(InventoryItem $item, ?int $exceptReservedShipmentId = null): float
    {
        $items = collect([$item]);
        $site = $this->writeSite();
        $warehouseId = $this->writeWarehouse($item->id);
        $onHand = $this->balancesAt($items, $site, $warehouseId)[$item->id]['final'];
        $reserved = $this->reservedForOpenShipments($items, $site, $exceptReservedShipmentId, $warehouseId)[$item->id] ?? 0;

        return max(0, $onHand - $reserved - $this->reservedForForge($item->id, $site, $warehouseId));
    }

    /**
     * "Create Bundle": consume RM/PM per the FG recipe (+ any wastage) and add FG.
     *
     * @param  array<int, float|string|null>  $wastage  component item id => extra qty wasted
     * @param  array<int, float|string|null>  $perUnit  component item id => qty per 1 FG for THIS run (made differently); empty = the recipe
     */
    public function produce(InventoryItem $fg, float $qty, array $wastage, ?string $notes, User $user, array $perUnit = [], ?array $componentIds = null, ?string $productionDate = null): InventoryProduction
    {
        if (! Schema::hasColumn('forge_work_orders', 'inventory_fg_item_id')) {
            throw ValidationException::withMessages([
                'fgItemId' => 'Bundle posting is temporarily unavailable while Forge inventory linkage is being repaired. Please try again shortly.',
            ]);
        }

        if (\App\Models\Forge\WorkOrder::where('inventory_fg_item_id', $fg->id)->exists()) {
            throw ValidationException::withMessages(['fgItemId' => 'This FG SKU uses Forge work orders. Release its batch through Forge QC to post stock once.']);
        }
        if ($fg->item_type !== 'fg') {
            throw ValidationException::withMessages(['fgItemId' => 'Only a Finished Good can be produced.']);
        }
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity to produce must be more than 0.']);
        }

        return DB::transaction(function () use ($fg, $qty, $wastage, $notes, $user, $perUnit, $componentIds, $productionDate) {
            $lines = $fg->bomLines()->with('component')->get();
            if ($componentIds !== null) {
                $ids = collect($componentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
                $components = InventoryItem::whereIn('id', $ids)->whereIn('item_type', ['rm', 'pm'])->get()->keyBy('id');
                if ($ids->isEmpty() || $components->count() !== $ids->count()) {
                    throw ValidationException::withMessages(['materials' => 'Add at least one valid Raw Material or Packaging Material.']);
                }

                $lines = $ids->map(function (int $id) use ($components, $perUnit) {
                    $perPiece = (float) ($perUnit[$id] ?? 0);
                    if ($perPiece <= 0) {
                        throw ValidationException::withMessages(["perUnit.{$id}" => 'Enter a material quantity greater than zero.']);
                    }

                    return (object) ['component_item_id' => $id, 'qty_per_unit' => $perPiece, 'component' => $components->get($id)];
                });
            }

            // This run's quantities per 1 FG: the recipe unless changed on the screen.
            $per = fn ($line) => (isset($perUnit[$line->component_item_id]) && $perUnit[$line->component_item_id] !== '')
                ? max(0, (float) $perUnit[$line->component_item_id]) : (float) $line->qty_per_unit;
            if ($lines->isEmpty() && $componentIds === null) {
                throw ValidationException::withMessages(['fgItemId' => "Add a recipe (RM/PM per 1 {$fg->unit}) to {$fg->displayName()} first."]);
            }

            // Lock the component rows so two people can't use the same stock at once.
            InventoryItem::whereIn('id', $lines->pluck('component_item_id'))->lockForUpdate()->get();
            $errors = [];
            foreach ($lines as $line) {
                $need = round($per($line) * $qty, 3);
                $waste = round(max(0, (float) ($wastage[$line->component_item_id] ?? 0)), 3);
                $warehouseId = $this->writeWarehouse($line->component_item_id);
                $component = collect([$line->component]);
                $balances = $this->balancesAt($component, $this->writeSite(), $warehouseId);
                $reserved = $this->reservedForOpenShipments($component, $this->writeSite(), null, $warehouseId);
                $have = max(0, $balances[$line->component_item_id]['final'] - (float) ($reserved[$line->component_item_id] ?? 0)
                    - $this->reservedForForge($line->component_item_id, $this->writeSite(), $warehouseId));
                if ($need + $waste > $have + 0.0005) {
                    $errors["wastage.{$line->component_item_id}"] = "Not enough {$line->component->displayName()}: need ".($need + $waste)." {$line->component->unit}, only {$have} in stock.";
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $production = InventoryProduction::create([
                'number' => 'TMP-'.Str::uuid(), 'fg_item_id' => $fg->id, 'qty_produced' => $qty,
                'notes' => $notes, 'created_by' => $user->id, 'location_id' => $this->writeSite(),
                'production_date' => $productionDate ?: now()->toDateString(),
            ]);
            $production->update(['number' => sprintf('PRD-%05d', $production->id)]);

            foreach ($lines as $line) {
                $used = round($per($line) * $qty, 3);
                if ($used > 0) {
                    $this->move($line->component_item_id, 'consumed', $used, $user, production: $production);
                }
                $waste = round(max(0, (float) ($wastage[$line->component_item_id] ?? 0)), 3);
                if ($waste > 0) {
                    $this->move($line->component_item_id, 'wastage', $waste, $user, production: $production);
                }
            }
            $this->move($fg->id, 'produced', $qty, $user, production: $production);

            return $production;
        });
    }

    /** Revise a recent bundle while preserving stock and movement history. */
    public function reviseProduction(InventoryProduction $production, float $qty, ?string $notes, User $user, ?string $productionDate = null): InventoryProduction
    {
        \App\Support\SiteLocation::assertCanAccessSiteRecord($user, $production->location_id);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity to produce must be more than 0.']);
        }

        return DB::transaction(function () use ($production, $qty, $notes, $user, $productionDate): InventoryProduction {
            $production = InventoryProduction::whereKey($production->id)->lockForUpdate()->firstOrFail();
            $moves = InventoryMovement::where('production_id', $production->id)->lockForUpdate()->get();
            $itemIds = $moves->pluck('item_id')->unique()->values();
            $items = InventoryItem::whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');
            $oldQty = (float) $production->qty_produced;
            if ($oldQty <= 0) {
                throw ValidationException::withMessages(['qty' => 'This bundle has no valid production quantity and cannot be revised.']);
            }

            $warehouseByItem = $moves->groupBy('item_id')->map(fn ($itemMoves) => $itemMoves->first()?->warehouse_id);
            $balances = [];
            $reserved = [];
            foreach ($items as $itemId => $item) {
                $warehouseId = $warehouseByItem[$itemId] ?: WarehouseStockResolver::forItemAtSite($production->location_id, $item);
                $balances[$itemId] = $this->balancesAt(collect([$item]), $production->location_id, $warehouseId)[$itemId];
                $reserved[$itemId] = $this->reservedForOpenShipments(collect([$item]), $production->location_id, null, $warehouseId)[$itemId] ?? 0;
            }
            $fgMove = $moves->first(fn ($move) => $move->type === 'produced' && (int) $move->item_id === (int) $production->fg_item_id);
            $fgBalanceWithoutThisBundle = ($balances[$production->fg_item_id]['final'] ?? 0) - (float) ($fgMove?->qty ?? $oldQty);
            if ($fgBalanceWithoutThisBundle + $qty < -0.0005) {
                throw ValidationException::withMessages(['qty' => 'The available finished-good stock has already been used; this bundle quantity cannot be reduced by that amount.']);
            }

            $adjustments = [];
            foreach ($moves->whereIn('type', ['consumed', 'wastage'])->groupBy('item_id') as $itemId => $itemMoves) {
                $usedMove = $itemMoves->firstWhere('type', 'consumed');
                $wasteMove = $itemMoves->firstWhere('type', 'wastage');
                $oldUsed = (float) ($usedMove?->qty ?? 0);
                $oldWaste = (float) ($wasteMove?->qty ?? 0);
                $newUsed = round(($oldUsed / $oldQty) * $qty, 3);
                $newWaste = round(($oldWaste / $oldQty) * $qty, 3);
                $availableWithoutThisBundle = ($balances[$itemId]['final'] ?? 0) + $oldUsed + $oldWaste - (float) ($reserved[$itemId] ?? 0);
                if ($newUsed + $newWaste > $availableWithoutThisBundle + 0.0005) {
                    $itemName = $items->get($itemId)?->displayName() ?? 'a component';
                    throw ValidationException::withMessages(['qty' => "Not enough {$itemName} stock to revise this bundle to {$qty}."]);
                }
                $adjustments[] = [$usedMove, 'consumed', $newUsed, (int) $itemId];
                $adjustments[] = [$wasteMove, 'wastage', $newWaste, (int) $itemId];
            }

            foreach ($adjustments as [$movement, $type, $newQty, $itemId]) {
                if ($newQty <= 0) {
                    $movement?->delete();
                } elseif ($movement) {
                    $movement->update(['qty' => $newQty]);
                } else {
                    InventoryMovement::create(['item_id' => $itemId, 'type' => $type, 'qty' => $newQty, 'production_id' => $production->id, 'created_by' => $user->id, 'location_id' => $production->location_id, 'warehouse_id' => $warehouseByItem[$itemId] ?: WarehouseStockResolver::forItemAtSite($production->location_id, $items->get($itemId))]);
                }
            }

            if ($fgMove) {
                $fgMove->update(['qty' => $qty]);
            } else {
                InventoryMovement::create(['item_id' => $production->fg_item_id, 'type' => 'produced', 'qty' => $qty, 'production_id' => $production->id, 'created_by' => $user->id, 'location_id' => $production->location_id, 'warehouse_id' => WarehouseStockResolver::forItemAtSite($production->location_id, $items->get($production->fg_item_id))]);
            }
            $production->update([
                'qty_produced' => $qty,
                'notes' => $notes,
                'production_date' => $productionDate ?: ($production->production_date?->toDateString() ?? $production->created_at->toDateString()),
            ]);

            return $production->fresh();
        });
    }

    // $anyItemType: transfers / job work may also send out RM or PM; sales stay FG/Trading only.
    public function dispatch(InventoryItem $item, float $qty, string $customer, ?string $reference, ?string $notes, User $user, bool $anyItemType = false, ?int $exceptReservedShipmentId = null): InventoryDispatch
    {
        if (\App\Models\Flow\InventoryLot::where('inventory_item_id', $item->id)
            ->whereIn('status', ['staged', 'available'])->exists()) {
            throw ValidationException::withMessages(['itemId' => 'This FG SKU has tracked Flow lots. Dispatch it through Flow to keep bin and stock balances together.']);
        }
        if (! $anyItemType && ! in_array($item->item_type, InventoryItem::SELLABLE_TYPES, true)) {
            throw ValidationException::withMessages(['itemId' => 'Only Finished Goods and Trading Goods can be dispatched.']);
        }
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity to dispatch must be more than 0.']);
        }

        return DB::transaction(function () use ($item, $qty, $customer, $reference, $notes, $user, $exceptReservedShipmentId) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $have = $this->availableToMove($item, $exceptReservedShipmentId);
            if ($qty > $have + 0.0005) {
                throw ValidationException::withMessages(['qty' => "Only {$have} {$item->unit} of {$item->displayName()} in stock."]);
            }

            $dispatch = InventoryDispatch::create([
                'number' => 'TMP-'.Str::uuid(), 'item_id' => $item->id, 'qty' => $qty,
                'customer_name' => $customer, 'reference' => $reference, 'notes' => $notes, 'created_by' => $user->id,
            ]);
            $dispatch->update(['number' => sprintf('DSP-%05d', $dispatch->id)]);
            $this->move($item->id, 'dispatched', $qty, $user, dispatch: $dispatch);

            return $dispatch;
        });
    }

    /**
     * Store Manager's stock correction: + adds, - removes (never below zero).
     * Recorded with who and why, and shown in the item's history.
     */
    public function adjust(InventoryItem $item, float $change, string $reason, User $user): void
    {
        $change = round($change, 3);
        if ($change == 0.0) {
            throw ValidationException::withMessages(['adjustQty' => 'Enter how much to add (e.g. 25) or remove (e.g. -10).']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['adjustReason' => 'Write why the stock is changing (e.g. physical count, damage).']);
        }

        DB::transaction(function () use ($item, $change, $reason, $user) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $have = $this->availableToMove($item);
            if ($change < 0 && abs($change) > $have + 0.0005) {
                throw ValidationException::withMessages(['adjustQty' => "Only {$have} {$item->unit} in stock - can't remove ".abs($change).'.']);
            }
            InventoryMovement::create([
                'item_id' => $item->id, 'type' => $change > 0 ? 'adjust_in' : 'adjust_out', 'qty' => abs($change),
                'note' => mb_substr(trim($reason), 0, 250), 'created_by' => $user->id, 'location_id' => $this->writeSite(), 'warehouse_id' => $this->writeWarehouse($item->id),
            ]);
        });
    }

    private function move(int $itemId, string $type, float $qty, User $user, ?InventoryProduction $production = null, ?InventoryDispatch $dispatch = null): void
    {
        InventoryMovement::create([
            'item_id' => $itemId, 'type' => $type, 'qty' => $qty,
            'production_id' => $production?->id, 'dispatch_id' => $dispatch?->id, 'created_by' => $user->id,
            'location_id' => $this->writeSite(), 'warehouse_id' => $this->writeWarehouse($itemId),
        ]);
    }
}
