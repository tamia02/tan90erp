<?php

use App\Models\Inventory\InventoryItem;
use Illuminate\Support\Collection;
use App\Support\RoleCapability;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Pagination\LengthAwarePaginator;

new #[Layout('layouts.app')] class extends Component
{
    use \App\Livewire\Concerns\PicksStockSite;
    use WithPagination;

    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
    }

    public string $type = '';
    public bool $showArchived = false;
    public string $search = '';
    public array $selectedRows = [];
    public bool $allMatchingSelected = false;
    public array $excludedRows = [];
    public int $perPage = 200;

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearRowSelection();
    }

    public function updatedType(): void
    {
        $this->resetPage();
        $this->clearRowSelection();
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
        $this->clearRowSelection();
    }

    public function updatedStockSite(): void
    {
        $this->resetPage();
        $this->clearRowSelection();
    }

    public function selectVisibleRows(): void
    {
        $this->allMatchingSelected = false;
        $this->excludedRows = [];
        $this->selectedRows = $this->stockSnapshot()['rows']->pluck('key')->values()->all();
    }

    public function selectAllMatchingRows(): void
    {
        $this->selectedRows = [];
        $this->excludedRows = [];
        $this->allMatchingSelected = true;
    }

    public function toggleExcludedRow(string $key): void
    {
        $this->excludedRows = in_array($key, $this->excludedRows, true)
            ? array_values(array_diff($this->excludedRows, [$key]))
            : [...$this->excludedRows, $key];
    }

    public function clearRowSelection(): void
    {
        $this->selectedRows = [];
        $this->allMatchingSelected = false;
        $this->excludedRows = [];
    }

    public function exportStockCsv()
    {
        return $this->downloadStockCsv(false);
    }

    public function exportAllStockCsv()
    {
        return $this->downloadStockCsv(true);
    }

    private function downloadStockCsv(bool $allData)
    {
        RoleCapability::requireExport('stock_ledger');

        $rows = $this->stockSnapshot(true, $allData)['allRows'];
        if (! $allData && $this->allMatchingSelected) {
            $excluded = array_fill_keys($this->excludedRows, true);
            $rows = $rows->reject(fn ($row) => isset($excluded[$row['key']]))->values();
        } elseif (! $allData && $this->selectedRows !== []) {
            $selectedKeys = array_fill_keys($this->selectedRows, true);
            $rows = $rows->filter(fn ($row) => isset($selectedKeys[$row['key']]))->values();
        }
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Item code', 'Item', 'Type', 'Unit', 'Location', 'Warehouse code', 'Warehouse', 'Opening', 'Received (GRN)', 'Produced', 'Consumed', 'Wastage', 'Dispatched', 'Adjusted', 'Reserved', 'Available']);
        foreach ($rows as $row) {
            $item = $row['item'];
            $warehouse = $row['warehouse'];
            $balance = $row['balance'];
            fputcsv($stream, [
                $item->code ?: '',
                $item->visibleNameFor(auth()->user()),
                $item->typeLabel(),
                $item->unit,
                $warehouse->location?->name ?? '',
                $warehouse->code,
                $warehouse->name,
                $balance['opening'] ?? 0,
                $balance['received'] ?? 0,
                $balance['produced'] ?? 0,
                $balance['consumed'] ?? 0,
                $balance['wastage'] ?? 0,
                $balance['dispatched'] ?? 0,
                $balance['adjusted'] ?? 0,
                $balance['reserved'] ?? 0,
                $balance['available'] ?? 0,
            ]);
        }
        rewind($stream);
        $scope = $allData ? 'all-data' : 'current-view';
        return response()->streamDownload(function () use ($stream) { fpassthru($stream); fclose($stream); }, 'inventory-stock-'.$scope.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredStockItems(bool $ignoreFilters = false): Collection
    {
        return InventoryItem::query()->when(! $ignoreFilters && $this->type, fn ($q) => $q->where('item_type', $this->type))
            ->when(! $ignoreFilters && ! $this->showArchived, fn ($q) => $q->where('active', true))
            ->when(! $ignoreFilters && trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('code', 'like', '%'.trim($this->search).'%')
                ->when(InventoryItem::canSeeSkuName(auth()->user()), fn ($w) => $w->orWhere('sku', 'like', '%'.trim($this->search).'%'), fn ($w) => $w->orWhere(fn ($n) => $n->whereNull('name')->where('sku', 'like', '%'.trim($this->search).'%')))))
            ->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')->get();
    }

    private function visibleStockWarehouses(bool $ignoreSelectedSite = false): Collection
    {
        $locations = \App\Support\SiteLocation::visibleLocations();
        $allowedIds = $locations->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $ignoreSelectedSite && $this->stockSite !== '' && in_array((int) $this->stockSite, $allowedIds, true)) {
            $locations = $locations->where('id', (int) $this->stockSite)->values();
        } elseif (! $ignoreSelectedSite && $this->stockSite !== '' && \App\Support\SiteLocation::current() === null) {
            $this->stockSite = '';
        }

        $warehouses = $locations->flatMap(fn ($location) => \App\Services\Inventory\WarehouseStockResolver::activeAt((int) $location->id))
            ->sortBy('code')->values();
        if ($warehouses->isNotEmpty()) {
            $loaded = \App\Models\Tan90\MasterData\Warehouse::with('location')->whereIn('id', $warehouses->pluck('id'))->get()->keyBy('id');
            $warehouses = $warehouses->map(fn ($warehouse) => $loaded->get($warehouse->id, $warehouse))->values();
        }

        return $warehouses;
    }

    private function warehouseBalances(Collection $items, Collection $warehouses): array
    {
        $warehouseBalances = [];
        foreach ($warehouses as $warehouse) {
            $siteInventory = app(\App\Services\Inventory\InventoryService::class)->atWarehouse((int) $warehouse->id);
            $balances = $siteInventory->balances($items);
            $reserved = $siteInventory->reservedForOpenShipments($items, (int) $warehouse->tan90_location_id, null, (int) $warehouse->id);
            foreach ($items as $stockItem) {
                $balances[$stockItem->id]['reserved'] = (float) ($reserved[$stockItem->id] ?? 0);
                $balances[$stockItem->id]['available'] = round(max(0, $balances[$stockItem->id]['final'] - $balances[$stockItem->id]['reserved']), 3);
            }
            $warehouseBalances[(int) $warehouse->id] = $balances;
        }

        return $warehouseBalances;
    }

    private function rowsFor(Collection $items, Collection $warehouses, array $warehouseBalances): Collection
    {
        return $items->flatMap(fn ($item) => $warehouses->map(fn ($warehouse) => [
            'key' => $item->id.':'.$warehouse->id,
            'item' => $item,
            'warehouse' => $warehouse,
            'balance' => $warehouseBalances[(int) $warehouse->id][$item->id],
        ]))->values();
    }

    private function stockSnapshot(bool $includeAllRows = false, bool $ignoreFilters = false): array
    {
        $all = $this->filteredStockItems($ignoreFilters);
        $warehouses = $this->visibleStockWarehouses($ignoreFilters);
        $warehouseBalances = $this->warehouseBalances($all, $warehouses);

        $active = fn ($item) => collect($warehouseBalances)->contains(fn ($balances) => collect($balances[$item->id] ?? [])->except('final')->contains(fn ($v) => abs($v) > 0.0005));
        $total = $all->count();
        $page = $this->getPage();
        $items = $all->sortBy(fn ($item) => $active($item) ? 0 : 1)->values()->forPage($page, $this->perPage);
        $pages = new LengthAwarePaginator($items, $total, $this->perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
        $rows = $this->rowsFor($items, $warehouses, $warehouseBalances);

        return [
            ...$this->stockSiteView(),
            'items' => $items,
            'pages' => $pages,
            'rows' => $rows,
            'warehouses' => $warehouses,
            'total' => $total,
            'totalRows' => $total * $warehouses->count(),
            'types' => InventoryItem::TYPES,
            'canExportStock' => RoleCapability::can(auth()->user(), 'exports', 'stock_ledger'),
            'canCreateBundle' => app(\App\Services\Access\AccessControlService::class)->can(auth()->user(), 'inventory.production.view'),
            ...($includeAllRows ? ['allRows' => $this->rowsFor($all, $warehouses, $warehouseBalances)] : []),
        ];
    }

    public function with(): array
    {
        return $this->stockSnapshot();
    }
}; ?>

<div>
    @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0'; @endphp
    @include('partials.inventory-tabs', ['current' => 'inventory.stock', 'title' => 'Inventory Summary', 'subtitle' => 'Available stock reflects open outward shipments as reserved. When a shipment is marked dispatched, the reservation is replaced by its stock movement.', 'actionUrl' => $canCreateBundle ? route('inventory.production.create') : null, 'actionLabel' => 'Create Bundle', 'actionTestId' => 'inventory-create-bundle-cta'])
    @include('partials.stock-site-picker', ['required' => false, 'separate' => true])

    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Inventory summary">
        <span class="rounded-full border px-3 py-1.5 text-xs font-semibold" style="border-color:var(--border);background:var(--surface-3);">{{ $total }} items</span>
        <span class="rounded-full border px-3 py-1.5 text-xs font-semibold" style="border-color:var(--border);background:var(--surface-3);">{{ $totalRows }} warehouse rows</span>
        @if ($allMatchingSelected || $selectedRows)<span class="rounded-full px-3 py-1.5 text-xs font-semibold" style="background:var(--status-warning-bg);color:var(--status-warning);">{{ $allMatchingSelected ? max(0, $totalRows - count($excludedRows)) : count($selectedRows) }} selected</span>@endif
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border p-3" style="border-color:var(--border);background:var(--surface-3);">
        <select wire:model.live="type" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            <option value="">All item types</option>
            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><input type="checkbox" wire:model.live="showArchived"> Show archived items</label>
        <input wire:model.live.debounce.400ms="search" type="search" aria-label="Search inventory items" placeholder="Search item or code…" class="min-h-11 rounded-lg border px-3 py-2 text-sm flex-1 min-w-[200px]" style="border-color: var(--border);background:var(--surface-1);">
        <span class="text-xs" style="color: var(--text-muted);">Showing {{ $rows->count() }} of {{ $totalRows }} warehouse rows</span>
        <button type="button" wire:click="selectVisibleRows" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Select this page</button>
        <button type="button" wire:click="selectAllMatchingRows" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Select all matching filters</button>
        @if ($selectedRows || $allMatchingSelected)<button type="button" wire:click="clearRowSelection" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-secondary);">Clear selection</button>@endif
        @if ($canExportStock)
            <button type="button" wire:click="exportStockCsv" wire:loading.attr="disabled" wire:target="exportStockCsv" class="min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--brand);">
                <span wire:loading.remove wire:target="exportStockCsv">{{ $selectedRows || $allMatchingSelected ? 'Export Selected' : 'Export Filtered' }}</span>
                <span wire:loading wire:target="exportStockCsv" role="status">Preparing CSV…</span>
            </button>
            <button type="button" wire:click="exportAllStockCsv" wire:loading.attr="disabled" wire:target="exportAllStockCsv" class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-50" style="border-color:var(--border);color:var(--brand);">
                <span wire:loading.remove wire:target="exportAllStockCsv">Export all data</span>
                <span wire:loading wire:target="exportAllStockCsv" role="status">Preparing full export…</span>
            </button>
        @endif
    </div>

    <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm min-w-[900px]">
            <thead>
                <tr class="text-left text-xs uppercase" style="color: var(--text-muted);">
                    <th class="px-3 py-3"><input type="checkbox" aria-label="Select this page" x-on:change="$event.target.checked ? $wire.call('selectVisibleRows') : $wire.call('clearRowSelection')"></th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Warehouse Code</th>
                    <th class="px-4 py-3 text-right">Current<br><span class="normal-case font-normal">(opening)</span></th>
                    <th class="px-4 py-3 text-right">+ Received<br><span class="normal-case font-normal">(GRN)</span></th>
                    <th class="px-4 py-3 text-right">+ Produced</th>
                    <th class="px-4 py-3 text-right">− Converted</th>
                    <th class="px-4 py-3 text-right">− Wastage</th>
                    <th class="px-4 py-3 text-right">− Dispatched</th>
                    <th class="px-4 py-3 text-right">± Adjusted</th>
                    <th class="px-4 py-3 text-right">− Reserved<br><span class="normal-case font-normal">(open shipments)</span></th>
                    <th class="px-4 py-3 text-right">= Available</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $item = $row['item'];
                        $warehouse = $row['warehouse'];
                        $b = $row['balance'];
                        $historyUrl = route('inventory.items.show', $item).'?location_id='.$warehouse->tan90_location_id.'&warehouse_id='.$warehouse->id.'&kind=';
                        $cell = fn ($kind, $value, $color) => '<a href="'.e($historyUrl.rawurlencode($kind)).'" wire:navigate class="hover:underline" style="color: '.$color.';" title="Open movement details">'.$fmt($value).'</a>';
                    @endphp
                    <tr wire:key="stock-{{ $item->id }}-warehouse-{{ $warehouse->id }}" style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-3">@if($allMatchingSelected)<input type="checkbox" @checked(!in_array($row['key'], $excludedRows, true)) wire:click="toggleExcludedRow('{{ $row['key'] }}')" aria-label="Select {{ $item->safeDisplayName() }} in {{ $warehouse->code }}">@else<input type="checkbox" wire:model.live="selectedRows" value="{{ $row['key'] }}" aria-label="Select {{ $item->safeDisplayName() }} in {{ $warehouse->code }}">@endif</td>
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);"><a href="{{ route('inventory.items.show', $item) }}" wire:navigate class="text-left hover:underline" title="Open full item and stock history">{{ $item->visibleNameFor(auth()->user()) }}</a> <span class="text-xs font-normal" style="color: var(--text-muted);">{{ $item->unit }}</span></td>
                        <td class="px-4 py-3 text-xs">{{ $item->typeLabel() }}</td>
                        <td class="px-4 py-3"><div class="font-semibold">{{ $warehouse->code }}</div><div class="text-xs" style="color: var(--text-muted);">{{ $warehouse->name }}</div></td>
                        <td class="px-4 py-3 text-right">{{ $fmt($b['opening']) }}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('received', $b['received'], 'var(--status-good)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('produced', $b['produced'], 'var(--status-good)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('consumed', $b['consumed'], 'var(--status-warning)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('wastage', $b['wastage'], 'var(--status-critical)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('dispatched', $b['dispatched'], 'var(--status-warning)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('adjusted', $b['adjusted'], 'var(--brand)') !!}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($b['reserved']) }}</td>
                        <td class="px-4 py-3 text-right font-bold">{{ $fmt($b['available']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items match these filters - start at <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $pages->links() }}</div>

</div>
