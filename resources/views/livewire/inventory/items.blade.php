<?php

use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrderLine;
use App\Models\SkuMaster;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
    }

    public ?int $editingId = null;
    public string $sku = '';
    public string $name = '';
    public string $itemType = 'rm';
    public string $unit = 'NOS';
    public string $openingQty = '0';

    public string $search = '';
    public string $typeFilter = '';
    public int $perPage = 100;

    public function updatedSearch(): void
    {
        $this->perPage = 100;
    }

    public function updatedTypeFilter(): void
    {
        $this->perPage = 100;
    }
    public function save(): void
    {
        $data = $this->validate([
            'sku' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'sku')->ignore($this->editingId)],
            'name' => ['nullable', 'string', 'max:255'],
            'itemType' => ['required', Rule::in(array_keys(InventoryItem::TYPES))],
            'unit' => ['required', 'string', 'max:20'],
            'openingQty' => ['required', 'numeric', 'min:0'],
        ], ['sku.unique' => 'This SKU is already added.']);

        $item = InventoryItem::updateOrCreate(['id' => $this->editingId], [
            'sku' => trim($data['sku']), 'name' => $data['name'] ?: null, 'item_type' => $data['itemType'],
            'unit' => strtoupper(trim($data['unit'])), 'opening_qty' => $data['openingQty'],
        ]);
        AuditLogger::log($this->editingId ? 'Inventory item updated' : 'Inventory item added', "{$item->sku} · {$item->typeLabel()} · opening {$item->opening_qty}", $item);

        session()->flash('success', "Saved {$item->displayName()}.");
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $this->editingId = $item->id;
        $this->sku = $item->sku;
        $this->name = (string) $item->name;
        $this->itemType = $item->item_type;
        $this->unit = $item->unit;
        $this->openingQty = rtrim(rtrim((string) $item->opening_qty, '0'), '.') ?: '0';
        $this->resetValidation(); // don't carry an earlier "already added" error onto the edit form
    }

    // Unused items are deleted outright. Items with stock history or used in a
    // recipe are archived instead (hidden from lists, history kept) so past
    // productions/dispatches and the Stock Summary stay correct.
    public function removeItem(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $inUse = $item->movements()->exists()
            || InventoryBomLine::where('component_item_id', $item->id)->exists();

        if ($inUse) {
            $item->update(['active' => false]);
            AuditLogger::log('Inventory item archived', $item->sku, $item);
            session()->flash('success', "Archived {$item->displayName()} - it has stock history or is used in a recipe, so it is hidden instead of deleted.");
        } else {
            $item->bomLines()->delete();
            AuditLogger::log('Inventory item deleted', $item->sku);
            $item->delete();
            session()->flash('success', "Deleted {$item->displayName()}.");
        }

        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    public function restoreItem(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $item->update(['active' => true]);
        session()->flash('success', "Restored {$item->displayName()}.");
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'sku', 'name', 'itemType', 'unit', 'openingQty']);
        $this->resetValidation();
    }

    public function with(): array
    {
        // 2,000+ Zoho items: search/filter and show them 100 at a time so every
        // click doesn't re-render the whole catalogue.
        $query = InventoryItem::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where('sku', 'like', '%'.trim($this->search).'%'))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('item_type', $this->typeFilter));
        $total = (clone $query)->count();
        $items = $query
            ->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')
            ->limit($this->perPage)->get();

        // Suggest SKU names already used by POs / GRN / SKU Master so stock links up.
        $known = collect()
            ->merge(SkuMaster::pluck('sku'))
            ->merge(LedgerEntry::distinct()->pluck('sku'))
            ->merge(PurchaseOrderLine::distinct()->pluck('product'))
            ->filter()->unique()->diff(InventoryItem::pluck('sku'))->sort()->values();

        return [
            'items' => $items,
            'total' => $total,
            'balances' => app(InventoryService::class)->balances($items),
            'types' => InventoryItem::TYPES,
            'knownSkus' => $known,
            // Where each item came from: Zoho, the ERP's own Items list, or only GRN.
            'zohoSkus' => SkuMaster::whereIn('sku', $items->pluck('sku'))->whereNotNull('zoho_item_id')->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip(),
            'erpSkus' => SkuMaster::whereIn('sku', $items->pluck('sku'))->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip(),
            'typeCounts' => InventoryItem::where('active', true)->groupBy('item_type')->selectRaw('item_type, COUNT(*) as c')->pluck('c', 'item_type'),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.items', 'title' => 'Items', 'subtitle' => 'All items in one list - every item from Items (Zoho) and anything received through GRN appears here automatically. Check the type (RM / PM / FG / Trading) and current stock. Recipes for Finished Goods are on the next tab.'])

    <form wire:submit="save" class="rounded-2xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ $editingId ? 'Edit item' : 'Add an item that is not in Items (Zoho)' }}</h2>
            <span class="text-xs" style="color: var(--text-muted);">"Current stock" = what you had before this module. GRN receipts are added automatically.</span>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-start">
            <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">SKU / item name (same as on the PO)</span>
                <input wire:model="sku" list="known-skus" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. PCM Raw Compound (TN-1 Grade)">
                <datalist id="known-skus">@foreach ($knownSkus as $known)<option value="{{ $known }}"></option>@endforeach</datalist>
                @error('sku') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Display name (optional)</span>
                <input wire:model="name" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Type</span>
                <select wire:model="itemType" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Unit</span>
                <input wire:model="unit" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="KG / NOS / BOX">
                @error('unit') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Current stock</span>
                <input wire:model="openingQty" type="number" step="0.001" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                @error('openingQty') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $editingId ? 'Save changes' : 'Add item' }}</button>
            @if ($editingId)<button type="button" wire:click="resetForm" class="rounded-lg border px-4 py-2 text-sm" style="border-color: var(--border);">Cancel</button>@endif
        </div>
    </form>

    <div>
        <div class="space-y-5">
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ($types as $value => $label)
                    <button type="button" wire:click="$set('typeFilter', '{{ $typeFilter === $value ? '' : $value }}')" class="rounded-full border px-3 py-1 font-semibold" style="{{ $typeFilter === $value ? 'background: var(--brand); color: #fff; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-secondary);' }}">{{ $label }}: {{ $typeCounts[$value] ?? 0 }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search items…" class="rounded-lg border px-3 py-2 text-sm flex-1 min-w-[200px]" style="border-color: var(--border);">
                <select wire:model.live="typeFilter" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">All types</option>
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <span class="text-xs" style="color: var(--text-muted);">Showing {{ $items->count() }} of {{ $total }}</span>
            </div>
            <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm min-w-[760px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="px-4 py-3">Item</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Source</th><th class="px-4 py-3 text-right">Opening stock</th><th class="px-4 py-3 text-right">In stock now</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="inv-item-{{ $item->id }}" style="border-top: 1px solid var(--border);{{ $item->active ? "" : " opacity: .55;" }}">
                                <td class="px-4 py-3"><div class="font-medium" style="color: var(--text-primary);">{{ $item->displayName() }}</div>@if ($item->name)<div class="text-xs" style="color: var(--text-muted);">{{ $item->sku }}</div>@endif</td>
                                <td class="px-4 py-3">{{ $item->typeLabel() }}</td>
                                <td class="px-4 py-3">{{ $item->unit }}</td>
                                <td class="px-4 py-3 text-xs">{{ $zohoSkus->has(mb_strtolower($item->sku)) ? 'Zoho' : ($erpSkus->has(mb_strtolower($item->sku)) ? 'ERP Items' : 'GRN / added here') }}</td>
                                <td class="px-4 py-3 text-right">{{ rtrim(rtrim((string) $item->opening_qty, '0'), '.') ?: '0' }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ rtrim(rtrim(number_format($balances[$item->id]['final'], 3, '.', ''), '0'), '.') }} {{ $item->unit }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if (! $item->active)
                                        <span class="text-xs font-semibold mr-2" style="color: var(--text-muted);">Archived</span>
                                        <button type="button" wire:click="restoreItem({{ $item->id }})" class="text-xs font-semibold" style="color: var(--brand);">Restore</button>
                                    @else
                                    <button type="button" wire:click="edit({{ $item->id }})" class="text-xs font-semibold" style="color: var(--brand);">Edit</button>
                                    <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="Remove {{ $item->displayName() }}? If it already has stock history it will be archived instead of deleted." class="text-xs font-semibold ml-3" style="color: var(--status-critical);">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet. Add items in SKU Master (Admin &gt; SKU Master) or receive them through GRN - they will appear here automatically.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($items->count() < $total)
                <button type="button" wire:click="$set('perPage', {{ $perPage + 100 }})" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Show more ({{ $total - $items->count() }} more)</button>
            @endif

        </div>
    </div>
</div>
