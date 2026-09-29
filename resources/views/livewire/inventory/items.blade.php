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
    public ?int $editingId = null;
    public string $sku = '';
    public string $name = '';
    public string $itemType = 'rm';
    public string $unit = 'NOS';
    public string $openingQty = '0';

    public ?int $recipeFgId = null;
    public string $componentId = '';
    public string $componentQty = '';

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
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'sku', 'name', 'itemType', 'unit', 'openingQty']);
        $this->resetValidation();
    }

    public function openRecipe(int $fgId): void
    {
        $this->recipeFgId = $fgId;
        $this->reset(['componentId', 'componentQty']);
    }

    public function addComponent(): void
    {
        $this->validate([
            'componentId' => ['required', Rule::exists('inventory_items', 'id')->whereIn('item_type', ['rm', 'pm'])],
            'componentQty' => ['required', 'numeric', 'gt:0'],
        ], ['componentId.required' => 'Pick a Raw or Packaging material.', 'componentQty.gt' => 'Quantity must be more than 0.']);

        InventoryBomLine::updateOrCreate(
            ['fg_item_id' => $this->recipeFgId, 'component_item_id' => (int) $this->componentId],
            ['qty_per_unit' => $this->componentQty],
        );
        $this->reset(['componentId', 'componentQty']);
    }

    public function removeComponent(int $lineId): void
    {
        InventoryBomLine::where('fg_item_id', $this->recipeFgId)->whereKey($lineId)->delete();
    }

    public function with(): array
    {
        $items = InventoryItem::with('bomLines.component')->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')->get();

        // Suggest SKU names already used by POs / GRN / SKU Master so stock links up.
        $known = collect()
            ->merge(SkuMaster::pluck('sku'))
            ->merge(LedgerEntry::distinct()->pluck('sku'))
            ->merge(PurchaseOrderLine::distinct()->pluck('product'))
            ->filter()->unique()->diff($items->pluck('sku'))->sort()->values();

        return [
            'items' => $items,
            'balances' => app(InventoryService::class)->balances($items),
            'types' => InventoryItem::TYPES,
            'knownSkus' => $known,
            'recipeFg' => $this->recipeFgId ? $items->firstWhere('id', $this->recipeFgId) : null,
            'componentOptions' => $items->whereIn('item_type', ['rm', 'pm']),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.items', 'title' => 'Items & Recipes', 'subtitle' => 'Mark every item as Raw Material, Packaging Material, Finished Good or Trading Good, set its current stock, and for each Finished Good list what it is made of.'])

    <div class="grid lg:grid-cols-3 gap-5">
        <form wire:submit="save" class="rounded-2xl border p-5 space-y-3 self-start" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $editingId ? 'Edit item' : 'Add item' }}</h2>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">SKU / item name (same as on the PO)</span>
                <input wire:model="sku" list="known-skus" class="rounded-lg border px-3 py-2" style="border-color: var(--border);" placeholder="e.g. PCM Raw Compound (TN-1 Grade)">
                <datalist id="known-skus">@foreach ($knownSkus as $known)<option value="{{ $known }}"></option>@endforeach</datalist>
                @error('sku') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Display name (optional)</span>
                <input wire:model="name" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Type</span>
                <select wire:model="itemType" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Unit</span>
                    <input wire:model="unit" class="rounded-lg border px-3 py-2" style="border-color: var(--border);" placeholder="KG / NOS / BOX">
                    @error('unit') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Current stock</span>
                    <input wire:model="openingQty" type="number" step="0.001" min="0" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                    @error('openingQty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
            </div>
            <p class="text-xs" style="color: var(--text-muted);">"Current stock" is what you already have before using this module. Goods received through GRN are added automatically - don't include them here.</p>
            <div class="flex gap-2">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $editingId ? 'Save changes' : 'Add item' }}</button>
                @if ($editingId)<button type="button" wire:click="resetForm" class="rounded-lg border px-4 py-2 text-sm" style="border-color: var(--border);">Cancel</button>@endif
            </div>
        </form>

        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm min-w-[640px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="px-4 py-3">Item</th><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">In stock now</th><th class="px-4 py-3">Recipe</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="inv-item-{{ $item->id }}" style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3"><div class="font-medium" style="color: var(--text-primary);">{{ $item->displayName() }}</div>@if ($item->name)<div class="text-xs" style="color: var(--text-muted);">{{ $item->sku }}</div>@endif</td>
                                <td class="px-4 py-3">{{ $item->typeLabel() }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ rtrim(rtrim(number_format($balances[$item->id]['final'], 3, '.', ''), '0'), '.') }} {{ $item->unit }}</td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($item->item_type === 'fg')
                                        @forelse ($item->bomLines as $line){{ rtrim(rtrim((string) $line->qty_per_unit, '0'), '.') }} {{ $line->component?->unit }} {{ $line->component?->displayName() }}@if (! $loop->last), @endif @empty <span style="color: var(--status-warning);">No recipe yet</span> @endforelse
                                    @else — @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $item->id }})" class="text-xs font-semibold" style="color: var(--brand);">Edit</button>
                                    @if ($item->item_type === 'fg')<button type="button" wire:click="openRecipe({{ $item->id }})" class="text-xs font-semibold ml-3" style="color: var(--brand);">Recipe</button>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet. Add your Raw Materials, Packaging Materials, Finished Goods and Trading Goods on the left.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($recipeFg)
                <div class="rounded-2xl border p-5 space-y-3" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between"><h2 class="text-lg font-semibold" style="color: var(--text-primary);">Recipe: 1 {{ $recipeFg->unit }} of {{ $recipeFg->displayName() }} needs</h2><button type="button" wire:click="$set('recipeFgId', null)" class="text-xs" style="color: var(--text-muted);">Close</button></div>
                    @foreach ($recipeFg->bomLines as $line)
                        <div wire:key="bom-{{ $line->id }}" class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                            <span>{{ rtrim(rtrim((string) $line->qty_per_unit, '0'), '.') }} {{ $line->component?->unit }} of {{ $line->component?->displayName() }} <span class="text-xs" style="color: var(--text-muted);">({{ $line->component?->typeLabel() }})</span></span>
                            <button type="button" wire:click="removeComponent({{ $line->id }})" class="text-xs font-semibold" style="color: var(--status-critical);">Remove</button>
                        </div>
                    @endforeach
                    <form wire:submit="addComponent" class="flex flex-wrap gap-2 items-start">
                        <div class="flex flex-col"><select wire:model="componentId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="">Pick RM / PM…</option>@foreach ($componentOptions as $option)<option value="{{ $option->id }}">{{ $option->displayName() }} ({{ $option->unit }})</option>@endforeach</select>@error('componentId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</div>
                        <div class="flex flex-col"><input wire:model="componentQty" type="number" step="0.0001" min="0" placeholder="Qty per 1 {{ $recipeFg->unit }}" class="rounded-lg border px-3 py-2 text-sm w-44" style="border-color: var(--border);">@error('componentQty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</div>
                        <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to recipe</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
