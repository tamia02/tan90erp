<?php

use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\ItemCustomField;
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
    public string $code = '';    // SKU Code
    public string $sku = '';     // SKU Name = exact chemical name (masked)
    public string $name = '';    // Display Name, chosen by the team
    public string $itemType = 'rm';
    public string $unit = 'NOS';
    public ?int $historyItemId = null;
    public array $customValues = [];

    // Admin: custom item fields (Number / Text / Drop-down).
    public string $newFieldLabel = '';
    public string $newFieldType = 'text';
    public string $newFieldOptions = '';
    public bool $newFieldRequired = false;

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
        $this->unit = strtoupper(trim($this->unit));
        $existing = $this->editingId ? InventoryItem::findOrFail($this->editingId) : null;
        // The SKU Name links the item to POs, GRN and Zoho, so it can't be
        // changed on an existing item - and is never shown to people who may
        // not see chemical names.
        if ($existing) {
            $this->sku = $existing->sku;
        }
        $data = $this->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'sku')->ignore($this->editingId)],
            'name' => ['nullable', 'string', 'max:255'],
            'itemType' => ['required', Rule::in(array_keys(InventoryItem::TYPES))],
            // From the Units master; an existing item may keep its old unit.
            'unit' => ['required', 'string', 'max:20', Rule::in(array_merge(\App\Support\Units::options(), [(string) $existing?->unit]))],
        ], ['sku.unique' => 'An item with this SKU Name already exists.', 'sku.required' => 'Enter the SKU Name (exact chemical / item name).'], ['sku' => 'SKU Name', 'code' => 'SKU Code', 'name' => 'Display Name']);
        $custom = $this->validatedCustomValues();

        $item = InventoryItem::updateOrCreate(['id' => $this->editingId], [
            'code' => trim($data['code'] ?? '') ?: null, 'sku' => trim($data['sku']), 'name' => trim($data['name'] ?? '') ?: null,
            'item_type' => $data['itemType'], 'unit' => strtoupper(trim($data['unit'])),
            'custom_fields' => $custom ?: null,
        ]);
        AuditLogger::log($this->editingId ? 'Inventory item updated' : 'Inventory item added', ($item->code ? "{$item->code} · " : '').$item->displayName()." · {$item->typeLabel()}", $item);

        session()->flash('success', "Saved {$item->displayName()}.");
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $this->editingId = $item->id;
        $this->code = (string) $item->code;
        $this->sku = $item->sku;
        $this->name = (string) $item->name;
        $this->itemType = $item->item_type;
        $this->unit = $item->unit;
        $this->customValues = array_map('strval', $item->custom_fields ?? []);
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
        $this->reset(['editingId', 'code', 'sku', 'name', 'itemType', 'unit', 'customValues']);
        $this->resetValidation();
    }

    /** @return array<string, string|float> */
    private function validatedCustomValues(): array
    {
        $fields = ItemCustomField::activeFields();
        $rules = [];
        $values = [];
        foreach ($fields as $field) {
            $rules["customValues.{$field->field_key}"] = array_values(array_filter([
                $field->required ? 'required' : 'nullable',
                $field->input_type === 'number' ? 'numeric' : 'string',
                $field->input_type === 'dropdown' ? Rule::in($field->options ?? []) : null,
                $field->input_type === 'text' ? 'max:255' : null,
            ]));
            $value = trim((string) ($this->customValues[$field->field_key] ?? ''));
            if ($value !== '') {
                $values[$field->field_key] = $field->input_type === 'number' ? (float) $value : $value;
            }
        }
        if ($rules) {
            $this->validate($rules, [], $fields->mapWithKeys(fn ($f) => ["customValues.{$f->field_key}" => $f->label])->all());
        }

        return $values;
    }

    public function addCustomField(): void
    {
        abort_unless(ItemCustomField::canManage(auth()->user()), 403);
        $this->validate([
            'newFieldLabel' => ['required', 'string', 'max:60'],
            'newFieldType' => ['required', Rule::in(array_keys(ItemCustomField::TYPES))],
            'newFieldOptions' => [Rule::requiredIf($this->newFieldType === 'dropdown'), 'nullable', 'string', 'max:1000'],
        ], ['newFieldOptions.required' => 'List the drop-down choices, separated by commas.']);

        $key = str($this->newFieldLabel)->slug('_')->limit(50, '')->toString() ?: 'field';
        $base = $key;
        for ($n = 2; ItemCustomField::where('field_key', $key)->exists(); $n++) {
            $key = "{$base}_{$n}";
        }
        $field = ItemCustomField::create([
            'field_key' => $key, 'label' => trim($this->newFieldLabel), 'input_type' => $this->newFieldType,
            'options' => $this->newFieldType === 'dropdown' ? array_values(array_unique(array_filter(array_map('trim', explode(',', $this->newFieldOptions))))) : null,
            'required' => $this->newFieldRequired, 'sort_order' => (int) ItemCustomField::max('sort_order') + 1,
        ]);
        AuditLogger::log('Item custom field added', "{$field->label} ({$field->input_type})", $field);
        session()->flash('success', "Custom field \"{$field->label}\" added - it now shows on every item.");
        $this->reset(['newFieldLabel', 'newFieldType', 'newFieldOptions', 'newFieldRequired']);
    }

    public function toggleCustomField(int $id): void
    {
        abort_unless(ItemCustomField::canManage(auth()->user()), 403);
        $field = ItemCustomField::findOrFail($id);
        $field->update(['active' => ! $field->active]);
        AuditLogger::log($field->active ? 'Item custom field turned on' : 'Item custom field turned off', $field->label, $field);
    }

    public function with(): array
    {
        // 2,000+ Zoho items: search/filter and show them 100 at a time so every
        // click doesn't re-render the whole catalogue.
        $canSeeSku = InventoryItem::canSeeSkuName(auth()->user());
        $term = '%'.trim($this->search).'%';
        $query = InventoryItem::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', $term)->orWhere('code', 'like', $term)
                ->when($canSeeSku, fn ($w) => $w->orWhere('sku', 'like', $term), fn ($w) => $w->orWhere(fn ($n) => $n->whereNull('name')->where('sku', 'like', $term)))
                ->orWhereIn('sku', SkuMaster::where('product_code', 'like', $term)->select('sku'))))
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

        $historyItem = $this->historyItemId ? InventoryItem::find($this->historyItemId) : null;

        return [
            'canSeeSku' => $canSeeSku,
            'historyItem' => $historyItem,
            'history' => $historyItem ? app(InventoryService::class)->history($historyItem) : collect(),
            'historyStock' => $historyItem ? app(InventoryService::class)->balances(collect([$historyItem]))[$historyItem->id] : null,
            'editingIsZoho' => $this->editingId && SkuMaster::where('sku', $this->sku)->whereNotNull('zoho_item_id')->exists(),
            'items' => $items,
            'total' => $total,
            'balances' => app(InventoryService::class)->balances($items),
            'types' => InventoryItem::TYPES,
            'units' => \App\Support\Units::options(),
            'customFields' => ItemCustomField::activeFields(),
            'canManageFields' => $canManage = ItemCustomField::canManage(auth()->user()),
            'allCustomFields' => $canManage ? ItemCustomField::orderBy('sort_order')->get() : collect(),
            'knownSkus' => $known,
            // Where each item came from: Zoho, the ERP's own Items list, or only GRN.
            'zohoSkus' => SkuMaster::whereIn('sku', $items->pluck('sku'))->whereNotNull('zoho_item_id')->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip(),
            'erpSkus' => SkuMaster::whereIn('sku', $items->pluck('sku'))->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip(),
            'codes' => SkuMaster::whereIn('sku', $items->pluck('sku'))->whereNotNull('product_code')->pluck('product_code', 'sku')->mapWithKeys(fn ($code, $sku) => [mb_strtolower($sku) => $code]),
            'typeCounts' => InventoryItem::where('active', true)->groupBy('item_type')->selectRaw('item_type, COUNT(*) as c')->pluck('c', 'item_type'),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.items', 'title' => 'Items', 'subtitle' => 'The one list of all items - every Zoho item (name and Zoho code) plus anything received through GRN appears here automatically. Click an item to see its stock history. Stock comes in through GRN and Create Bundle. Recipes for Finished Goods are under Composite Items (Recipes).'])

    @if ($canManageFields)
        <details class="rounded-2xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);" @if ($errors->hasAny(['newFieldLabel', 'newFieldOptions'])) open @endif>
            <summary class="text-base font-semibold cursor-pointer" style="color: var(--text-primary);">Custom item fields - {{ $allCustomFields->where('active', true)->count() }} active</summary>
            <p class="text-xs mt-1 mb-3" style="color: var(--text-muted);">Add a field once (e.g. Cost Price) and it appears on every item's form and in the list below. Turn a field off to hide it; values already filled are kept.</p>
            <div class="flex flex-wrap gap-2 mb-3">
                @forelse ($allCustomFields as $field)
                    <span wire:key="cf-{{ $field->id }}" class="rounded-full border px-3 py-1 text-xs" style="border-color: var(--border); {{ $field->active ? '' : 'opacity: .55;' }}">
                        {{ $field->label }} · {{ \App\Models\Inventory\ItemCustomField::TYPES[$field->input_type] }}{{ $field->input_type === 'dropdown' ? ' ('.implode(', ', $field->options ?? []).')' : '' }}{{ $field->required ? ' · required' : '' }}
                        <button type="button" wire:click="toggleCustomField({{ $field->id }})" class="font-semibold ml-1" style="color: var(--brand);">{{ $field->active ? 'Turn off' : 'Turn on' }}</button>
                    </span>
                @empty
                    <span class="text-xs" style="color: var(--text-muted);">No custom fields yet.</span>
                @endforelse
            </div>
            <form wire:submit="addCustomField" class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3 items-start">
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Field name</span>
                    <input wire:model="newFieldLabel" placeholder="e.g. Cost Price" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @error('newFieldLabel') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Type</span>
                    <select wire:model.live="newFieldType" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @foreach (\App\Models\Inventory\ItemCustomField::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select></label>
                @if ($newFieldType === 'dropdown')
                    <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">Choices (comma separated)</span>
                        <input wire:model="newFieldOptions" placeholder="e.g. Grade A, Grade B, Grade C" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('newFieldOptions') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
                @endif
                <label class="flex items-center gap-2 text-xs mt-6"><input type="checkbox" wire:model="newFieldRequired"> Required</label>
                <div class="lg:col-span-5"><button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add custom field</button></div>
            </form>
        </details>
    @endif

    <form wire:submit="save" class="rounded-2xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ $editingId ? 'Edit item' : 'Add an item that is not in Zoho' }}</h2>
            <span class="text-xs" style="color: var(--text-muted);">SKU Name = exact chemical / item name, seen only by people allowed to. Everyone else sees the Display Name and Code.</span>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-start">
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">SKU Code</span>
                <input wire:model="code" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. RM-PCM-018">
                @error('code') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">SKU Name (exact chemical name)</span>
                @if ($editingId && ! $canSeeSku)
                    <input value="Hidden - you can't see chemical names" disabled class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-2);">
                @elseif ($editingId)
                    <input wire:model="sku" disabled class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-2);">
                    <span style="color: var(--text-muted);">{{ $editingIsZoho ? 'Comes from Zoho - change it in Zoho.' : 'Fixed once saved - it links the item to POs and GRN.' }}</span>
                @else
                    <input wire:model="sku" list="known-skus" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Same as on the PO, e.g. N-Tetradecane">
                    <datalist id="known-skus">
                        @if ($canSeeSku)
                            @foreach ($knownSkus as $known)<option value="{{ $known }}"></option>@endforeach
                        @endif
                    </datalist>
                @endif
                @error('sku') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Display Name</span>
                <input wire:model="name" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="What the team sees">
                @error('name') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Type</span>
                <select wire:model="itemType" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Unit</span>
                <select wire:model="unit" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($units as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                    @if ($unit !== '' && ! in_array($unit, $units, true))<option value="{{ $unit }}">{{ $unit }} (not in Units master)</option>@endif
                </select>
                @error('unit') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            @foreach ($customFields as $field)
                <label wire:key="cf-input-{{ $field->id }}" class="flex flex-col gap-1 text-xs"><span class="font-medium">{{ $field->label }}{{ $field->required ? ' *' : '' }}</span>
                    @if ($field->input_type === 'dropdown')
                        <select wire:model="customValues.{{ $field->field_key }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                            <option value="">-</option>
                            @foreach ($field->options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                        </select>
                    @else
                        <input wire:model="customValues.{{ $field->field_key }}" type="{{ $field->input_type === 'number' ? 'number' : 'text' }}" step="any" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @endif
                    @error('customValues.'.$field->field_key) <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $editingId ? 'Save changes' : 'Add item' }}</button>
            @if ($editingId)<button type="button" wire:click="resetForm" class="rounded-lg border px-4 py-2 text-sm" style="border-color: var(--border);">Cancel</button>@endif
        </div>
    </form>

    <div>
        <div class="space-y-5">
            @if ($historyItem)
                <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--brand);">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <h2 class="text-base font-semibold" style="color: var(--text-primary);">Stock history: {{ $historyItem->displayName() }}{{ ($historyItem->code ?: ($codes[mb_strtolower($historyItem->sku)] ?? '')) ? ' · '.($historyItem->code ?: $codes[mb_strtolower($historyItem->sku)]) : '' }}</h2>
                        <button type="button" wire:click="$set('historyItemId', null)" class="text-xs" style="color: var(--text-muted);">Close</button>
                    </div>
                    @php $f = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0'; @endphp
                    <p class="text-xs mb-2" style="color: var(--text-secondary);">In stock now: <b>{{ $f($historyStock['final']) }} {{ $historyItem->unit }}</b> = received {{ $f($historyStock['received']) }} + produced {{ $f($historyStock['produced']) }} − used {{ $f($historyStock['consumed']) }} − wastage {{ $f($historyStock['wastage']) }} − dispatched {{ $f($historyStock['dispatched']) }}{{ (float) $historyStock['opening'] ? ' + earlier stock '.$f($historyStock['opening']) : '' }}</p>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left uppercase" style="color: var(--text-muted);"><th class="py-1.5">When</th><th class="py-1.5">What happened</th><th class="py-1.5 text-right">Qty</th></tr></thead>
                        <tbody>
                            @forelse ($history as $row)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-1.5 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row['at'])->format('d M Y, H:i') }}</td>
                                    <td class="py-1.5">{{ $row['label'] }}</td>
                                    <td class="py-1.5 text-right font-semibold" style="color: {{ $row['qty'] >= 0 ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $row['qty'] >= 0 ? '+' : '−' }}{{ $f(abs($row['qty'])) }} {{ $historyItem->unit }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-3" style="color: var(--text-muted);">No stock movement yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ($types as $value => $label)
                    <button type="button" wire:click="$set('typeFilter', '{{ $typeFilter === $value ? '' : $value }}')" class="rounded-full border px-3 py-1 font-semibold" style="{{ $typeFilter === $value ? 'background: var(--brand); color: #fff; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-secondary);' }}">{{ $label }}: {{ $typeCounts[$value] ?? 0 }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search by item name or code…" class="rounded-lg border px-3 py-2 text-sm flex-1 min-w-[200px]" style="border-color: var(--border);">
                <select wire:model.live="typeFilter" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">All types</option>
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <span class="text-xs" style="color: var(--text-muted);">Showing {{ $items->count() }} of {{ $total }}</span>
            </div>
            <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm min-w-[760px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="px-4 py-3">Item</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Source</th><th class="px-4 py-3 text-right">In stock now</th>@foreach ($customFields as $field)<th class="px-4 py-3">{{ $field->label }}</th>@endforeach<th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="inv-item-{{ $item->id }}" style="border-top: 1px solid var(--border);{{ $item->active ? "" : " opacity: .55;" }}">
                                <td class="px-4 py-3"><button type="button" wire:click="$set('historyItemId', {{ $item->id }})" class="text-left font-medium hover:underline" style="color: var(--text-primary);" title="See stock history">{{ $item->displayName() }}</button>@if ($item->name && $canSeeSku)<div class="text-xs" style="color: var(--text-muted);">SKU Name: {{ $item->sku }}</div>@endif</td>
                                <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $item->code ?: ($codes[mb_strtolower($item->sku)] ?? '') }}</td>
                                <td class="px-4 py-3">{{ $item->typeLabel() }}</td>
                                <td class="px-4 py-3">{{ $item->unit }}</td>
                                <td class="px-4 py-3 text-xs">{{ $zohoSkus->has(mb_strtolower($item->sku)) ? 'Zoho' : ($erpSkus->has(mb_strtolower($item->sku)) ? 'ERP Items' : 'GRN / added here') }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ rtrim(rtrim(number_format($balances[$item->id]['final'], 3, '.', ''), '0'), '.') }} {{ $item->unit }}</td>
                                @foreach ($customFields as $field)<td class="px-4 py-3 text-xs">{{ $item->custom_fields[$field->field_key] ?? '' }}</td>@endforeach
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
                            <tr><td colspan="{{ 7 + $customFields->count() }}" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet. Add items in SKU Master (Admin &gt; SKU Master) or receive them through GRN - they will appear here automatically.</td></tr>
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
