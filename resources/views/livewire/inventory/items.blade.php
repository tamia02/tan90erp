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
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
    }

    public ?int $editingId = null;
    public string $code = '';    // SKU Code
    public string $sku = '';     // internal link key (POs / GRN / Zoho) - never shown
    public string $name = '';    // Display Name, chosen by the team
    public string $itemType = 'rm';
    public string $unit = 'NOS';
    public ?int $historyItemId = null;

    // Edit pop-up: stock correction (+ adds, - removes) with a reason.
    public string $adjustQty = '';
    public string $adjustReason = '';

    // Import CSV
    public $importFile;
    public array $importErrors = [];
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
        // The internal SKU links the item to POs, GRN and Zoho: kept as is on an
        // existing item; a new item is linked by its Display Name.
        $this->sku = $existing ? $existing->sku : trim($this->name);
        $data = $this->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'sku')->ignore($this->editingId)],
            'itemType' => ['required', Rule::in(array_keys(InventoryItem::TYPES))],
            // From the Units master; an existing item may keep its old unit.
            'unit' => ['required', 'string', 'max:20', Rule::in(array_merge(\App\Support\Units::options(), [(string) $existing?->unit]))],
            'adjustQty' => ['nullable', 'numeric'],
            'adjustReason' => [Rule::requiredIf(fn () => trim($this->adjustQty) !== '' && (float) $this->adjustQty != 0), 'nullable', 'string', 'max:250'],
        ], ['sku.unique' => 'An item with this name already exists.', 'name.required' => 'Enter the Display Name.', 'adjustReason.required' => 'Write why the stock is changing (e.g. physical count, damage).'],
            ['code' => 'SKU Code', 'name' => 'Display Name', 'adjustQty' => 'stock change']);
        $custom = $this->validatedCustomValues();

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $custom, $existing, &$item) {
            $item = InventoryItem::updateOrCreate(['id' => $this->editingId], [
                'code' => trim($data['code'] ?? '') ?: null, 'sku' => $this->sku, 'name' => trim($data['name'] ?? '') ?: null,
                'item_type' => $data['itemType'], 'unit' => strtoupper(trim($data['unit'])),
                'custom_fields' => $custom ?: null,
            ]);
            if (trim($this->adjustQty) !== '' && (float) $this->adjustQty != 0) {
                app(InventoryService::class)->adjust($item, (float) $this->adjustQty, $this->adjustReason, auth()->user());
            }
        });
        $label = $this->maskedLabel($item);
        AuditLogger::log($this->editingId ? 'Inventory item updated' : 'Inventory item added', "{$label} · {$item->typeLabel()}".((float) $this->adjustQty ? " · stock {$this->adjustQty} ({$this->adjustReason})" : ''), $item);

        session()->flash('success', "Saved {$label}.".((float) $this->adjustQty ? ' Stock '.((float) $this->adjustQty > 0 ? '+' : '').$this->adjustQty." {$item->unit}." : ''));
        $this->resetForm();
    }

    // Every item as a CSV the team can edit in Excel and import back.
    public function exportItemsCsv()
    {
        $fields = ItemCustomField::activeFields();
        $codes = SkuMaster::whereNotNull('product_code')->pluck('product_code', 'sku')->mapWithKeys(fn ($c, $sku) => [mb_strtolower($sku) => $c]);
        $items = InventoryItem::where('active', true)->orderBy('id')->get();

        return response()->streamDownload(function () use ($fields, $codes, $items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['ID', 'SKU Code', 'Display Name', 'Type', 'Unit'], $fields->pluck('label')->all()));
            foreach ($items as $item) {
                fputcsv($out, array_merge(
                    [$item->id, $item->code ?: ($codes[mb_strtolower($item->sku)] ?? ''), $item->name ?? '', strtoupper($item->item_type === 'trading' ? 'Trading' : $item->item_type), $item->unit],
                    $fields->map(function ($f) use ($item) {
                        $v = $item->custom_fields[$f->field_key] ?? null;

                        return $v === null ? '' : ($f->input_type === 'yes_no' ? ($v ? 'Yes' : 'No') : (string) $v);
                    })->all(),
                ));
            }
            fclose($out);
        }, 'items-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Rows with an ID or SKU Code that matches update that item; other rows
    // create new items (Display Name, Type and Unit needed). Everything is
    // checked first - one bad row and nothing is saved.
    public function importItemsCsv(): void
    {
        $this->importErrors = [];
        $this->validate(['importFile' => ['required', 'file', 'mimes:csv,txt', 'max:10240']], ['importFile.required' => 'Choose the CSV file first.']);
        $fields = ItemCustomField::activeFields();
        $units = array_map('strtoupper', \App\Support\Units::options());
        $handle = fopen($this->importFile->getRealPath(), 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);
        $cId = $col('id');
        $cCode = $col('sku code', 'code');
        $cName = $col('display name', 'name');
        $cType = $col('type', 'item type');
        $cUnit = $col('unit');
        if ($cCode === null && $cName === null && $cId === null) {
            $this->importErrors[] = 'The first row must have the column names: ID, SKU Code, Display Name, Type, Unit - start from "Download items CSV".';
            fclose($handle);

            return;
        }
        $fieldCols = $fields->mapWithKeys(fn ($f) => [$f->field_key => $col(mb_strtolower($f->label), $f->field_key)])->filter(fn ($i) => $i !== null);
        $rules = collect(\App\Support\CustomFields::rules($fields, 'v'))->map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'nullable' : $x, $r))->all();
        $names = \App\Support\CustomFields::attributeNames($fields, 'v');
        $zohoCodes = SkuMaster::whereNotNull('product_code')->pluck('sku', 'product_code')->mapWithKeys(fn ($sku, $c) => [mb_strtolower($c) => $sku]);
        $typeOf = fn (string $t) => match (true) {
            $t === '' => null,
            in_array($t, ['rm', 'raw', 'raw material', 'raw material (rm)'], true) => 'rm',
            in_array($t, ['pm', 'packaging', 'packaging material', 'packaging material (pm)'], true) => 'pm',
            in_array($t, ['fg', 'finished', 'finished good', 'finished goods', 'finished good (fg)'], true) => 'fg',
            in_array($t, ['trading', 'trading good', 'trading goods'], true) => 'trading',
            default => 'bad',
        };

        $plan = [];
        $seenNames = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $cell = fn ($i) => $i === null ? '' : trim((string) ($row[$i] ?? ''));
            $id = $cell($cId);
            $code = $cell($cCode);
            $name = $cell($cName);
            $type = $typeOf(mb_strtolower($cell($cType)));
            $unit = strtoupper($cell($cUnit));
            $item = $id !== '' ? InventoryItem::find((int) $id) : null;
            if ($id !== '' && ! $item) {
                $this->importErrors[] = "Row {$line}: ID {$id} is not an item - clear the ID to add it as a new item.";

                continue;
            }
            if (! $item && $code !== '') {
                $item = InventoryItem::where('code', $code)->first() ?? (isset($zohoCodes[mb_strtolower($code)]) ? InventoryItem::where('sku', $zohoCodes[mb_strtolower($code)])->first() : null);
            }
            if ($type === 'bad') {
                $this->importErrors[] = "Row {$line}: Type must be RM, PM, FG or Trading.";
            }
            if ($unit !== '' && ! in_array($unit, $units, true) && $unit !== strtoupper((string) $item?->unit)) {
                $this->importErrors[] = "Row {$line}: Unit \"{$unit}\" is not in Master Data > Units.";
            }
            if (! $item) {
                if ($name === '' || ! $type || $unit === '') {
                    $this->importErrors[] = "Row {$line}: a new item needs Display Name, Type and Unit.";
                } elseif (InventoryItem::where('sku', $name)->exists() || isset($seenNames[mb_strtolower($name)])) {
                    $this->importErrors[] = "Row {$line}: an item called \"{$name}\" already exists - put its ID or SKU Code to update it.";
                }
                $seenNames[mb_strtolower($name)] = true;
            }
            $values = [];
            foreach ($fieldCols as $key => $i) {
                $v = $cell($i);
                if ($v === '') {
                    continue;
                }
                $f = $fields->firstWhere('field_key', $key);
                if ($f->input_type === 'yes_no') {
                    $v = in_array(mb_strtolower($v), ['yes', 'y', '1', 'true'], true) ? '1' : (in_array(mb_strtolower($v), ['no', 'n', '0', 'false'], true) ? '0' : $v);
                }
                if ($f->input_type === 'date') {
                    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
                        if (($d = \DateTime::createFromFormat('!'.$fmt, $v)) && $d->format($fmt) === $v) {
                            $v = $d->format('Y-m-d');
                            break;
                        }
                    }
                }
                $values[$key] = $v;
            }
            foreach (validator(['v' => $values], array_intersect_key($rules, array_flip(array_map(fn ($k) => "v.{$k}", array_keys($values)))), [], $names)->errors()->all() as $message) {
                $this->importErrors[] = "Row {$line}: {$message}";
            }
            $plan[] = ['item' => $item, 'code' => $code, 'name' => $name, 'type' => $type, 'unit' => $unit, 'values' => \App\Support\CustomFields::clean($fields, $values)];
        }
        fclose($handle);

        if ($this->importErrors) {
            array_unshift($this->importErrors, 'Nothing was imported. Fix these rows and upload again:');
            $this->importErrors = array_slice($this->importErrors, 0, 51);

            return;
        }
        $created = $updated = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($plan, &$created, &$updated) {
            foreach ($plan as $p) {
                if ($p['item']) {
                    $p['item']->update(array_filter([
                        'code' => $p['code'] ?: null, 'name' => $p['name'] ?: null, 'item_type' => $p['type'] ?: null, 'unit' => $p['unit'] ?: null,
                        'custom_fields' => $p['values'] ? array_merge($p['item']->custom_fields ?? [], $p['values']) : null,
                    ], fn ($v) => $v !== null));
                    $updated++;
                } else {
                    InventoryItem::create(['sku' => $p['name'], 'code' => $p['code'] ?: null, 'name' => $p['name'], 'item_type' => $p['type'], 'unit' => $p['unit'], 'opening_qty' => 0, 'custom_fields' => $p['values'] ?: null]);
                    $created++;
                }
            }
        });
        AuditLogger::log('Items imported from CSV', "{$created} added, {$updated} updated");
        session()->flash('success', "Import done: {$created} items added, {$updated} updated.");
        $this->reset(['importFile']);
    }

    // What people see: SKU Code + Display Name - never the hidden SKU name.
    private function maskedLabel(InventoryItem $item): string
    {
        $code = $item->code ?: SkuMaster::where('sku', $item->sku)->value('product_code');

        return trim(implode(' · ', array_filter([$code, $item->name])), ' ·') ?: ($code ?: 'item');
    }

    public function edit(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $this->editingId = $item->id;
        // Zoho items: start from the Zoho code if no ERP code is set yet.
        $this->code = (string) ($item->code ?: SkuMaster::where('sku', $item->sku)->value('product_code'));
        $this->sku = $item->sku;
        $this->name = (string) $item->name;
        $this->itemType = $item->item_type;
        $this->unit = $item->unit;
        $this->customValues = array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $item->custom_fields ?? []);
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
        $this->reset(['editingId', 'code', 'sku', 'name', 'itemType', 'unit', 'customValues', 'adjustQty', 'adjustReason']);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    private function validatedCustomValues(): array
    {
        $fields = ItemCustomField::activeFields();
        if ($fields->isNotEmpty()) {
            $this->validate(\App\Support\CustomFields::rules($fields, 'customValues'), [], \App\Support\CustomFields::attributeNames($fields, 'customValues'));
        }

        return \App\Support\CustomFields::clean($fields, $this->customValues);
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
            'editingItem' => $this->editingId ? InventoryItem::with(['createdBy', 'updatedBy'])->find($this->editingId) : null,
            'editingStock' => $this->editingId ? app(InventoryService::class)->balances(InventoryItem::whereKey($this->editingId)->get())[$this->editingId]['final'] ?? 0 : null,
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
            <p class="text-xs mt-1 mb-3" style="color: var(--text-muted);">Add a field once (e.g. Cost Price) and it appears on every item's form and in the list below. Turn a field off to hide it; values already filled are kept. Fields for other tables (Vendors, Customers, ...): <a href="{{ route('custom-fields.index') }}" wire:navigate class="underline" style="color: var(--brand);">Master Data &gt; Custom Fields</a>.</p>
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

    @if ($editingId)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto" style="background: rgba(15, 23, 42, .45);" role="dialog" aria-modal="true" wire:key="item-edit-popup" wire:click.self="resetForm" x-data x-on:keydown.escape.window="$wire.resetForm()">
    @endif
    <form wire:submit="save" class="rounded-2xl border {{ $editingId ? 'p-5 w-full max-w-4xl mt-16 shadow-2xl' : 'p-4 mb-5' }}" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <div>
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ $editingId ? 'Edit item' : 'Add an item that is not in Zoho' }}</h2>
                @if ($editingItem)
                    <div class="text-xs" style="color: var(--text-muted);">Added {{ $editingItem->created_at?->format('d M Y, H:i') }} by {{ $editingItem->createdBy?->name ?? 'Zoho sync / system' }} · Last updated {{ $editingItem->updated_at?->format('d M Y, H:i') }} by {{ $editingItem->updatedBy?->name ?? 'Zoho sync / system' }}</div>
                @endif
            </div>
            <span class="text-xs" style="color: var(--text-muted);">People see the SKU Code and Display Name.</span>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-start">
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">SKU Code</span>
                <input wire:model="code" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. RM-PCM-018">
                @error('code') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">Display Name{{ $editingId ? '' : ' *' }}</span>
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
                    @include('partials.custom-field-input', ['field' => $field, 'wire' => 'customValues.'.$field->field_key])
                    @error('customValues.'.$field->field_key) <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
        @if ($editingId)
            <div class="grid sm:grid-cols-3 gap-3 mt-4 rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                <div class="text-xs sm:col-span-3"><span class="font-semibold" style="color: var(--text-primary);">Adjust stock</span> <span style="color: var(--text-muted);">- in stock now: <b>{{ rtrim(rtrim(number_format((float) $editingStock, 3, '.', ''), '0'), '.') ?: '0' }} {{ $unit }}</b>. Type + to add (e.g. 25) or - to remove (e.g. -10). Shown in the item's history.</span></div>
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Change ({{ $unit }})</span>
                    <input wire:model="adjustQty" type="number" step="any" placeholder="e.g. 25 or -10" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @error('adjustQty') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
                <label class="flex flex-col gap-1 text-xs sm:col-span-2"><span class="font-medium">Reason</span>
                    <input wire:model="adjustReason" placeholder="e.g. Physical count 10 Oct, damaged in storage" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @error('adjustReason') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            </div>
        @endif
        <div class="flex gap-2 mt-3">
            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $editingId ? 'Save changes' : 'Add item' }}</button>
            @if ($editingId)<button type="button" wire:click="resetForm" class="rounded-lg border px-4 py-2 text-sm" style="border-color: var(--border);">Cancel</button>@endif
        </div>
    </form>
    @if ($editingId)
        </div>
    @endif

    <details class="rounded-2xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);" @if ($importErrors) open @endif>
        <summary class="text-base font-semibold cursor-pointer" style="color: var(--text-primary);">Import items (CSV)</summary>
        <p class="text-xs mt-1 mb-3" style="color: var(--text-muted);">1. Download the items CSV (ID, SKU Code, Display Name, Type, Unit and your custom fields). 2. Edit it in Excel - change items, or add new rows without an ID for new items (Display Name, Type = RM / PM / FG / Trading, Unit needed). 3. Upload it. Blank cells are left as they are. Every row is checked first - if anything is wrong, nothing is imported and you see the rows to fix.</p>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="exportItemsCsv" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download items CSV</button>
            <form wire:submit="importItemsCsv" class="flex flex-wrap items-center gap-2">
                <input type="file" wire:model="importFile" accept=".csv,text/csv" class="text-xs">
                <button type="submit" wire:loading.attr="disabled" wire:target="importFile,importItemsCsv" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Import CSV</button>
            </form>
        </div>
        @error('importFile') <div class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</div> @enderror
        @if ($importErrors)
            <div role="alert" class="rounded-lg border p-3 mt-3 text-xs space-y-0.5" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                @foreach ($importErrors as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
    </details>

    <div>
        <div class="space-y-5">
            @if ($historyItem)
                <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto" style="background: rgba(15, 23, 42, .45);" role="dialog" aria-modal="true" wire:key="item-history-popup" wire:click.self="$set('historyItemId', null)" x-data x-on:keydown.escape.window="$wire.set('historyItemId', null)">
                <div class="rounded-2xl border p-5 w-full max-w-3xl mt-16 shadow-2xl" style="background: var(--surface-3); border-color: var(--brand);">
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
                                <td class="px-4 py-3"><button type="button" wire:click="$set('historyItemId', {{ $item->id }})" class="text-left font-medium hover:underline" style="color: var(--text-primary);" title="See stock history">{{ $item->displayName() }}</button></td>
                                <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $item->code ?: ($codes[mb_strtolower($item->sku)] ?? '') }}</td>
                                <td class="px-4 py-3">{{ $item->typeLabel() }}</td>
                                <td class="px-4 py-3">{{ $item->unit }}</td>
                                <td class="px-4 py-3 text-xs">{{ $zohoSkus->has(mb_strtolower($item->sku)) ? 'Zoho' : ($erpSkus->has(mb_strtolower($item->sku)) ? 'ERP Items' : 'GRN / added here') }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ rtrim(rtrim(number_format($balances[$item->id]['final'], 3, '.', ''), '0'), '.') }} {{ $item->unit }}</td>
                                @foreach ($customFields as $field)<td class="px-4 py-3 text-xs">{{ \App\Support\CustomFields::display($field, $item->custom_fields[$field->field_key] ?? null) }}</td>@endforeach
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
