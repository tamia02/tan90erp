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
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use \App\Livewire\Concerns\PicksStockSite;

    use WithFileUploads;
    use WithPagination;

    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
        $this->exportColumns = $this->allExportColumns();
    }

    public ?int $editingId = null;
    public string $code = '';    // SKU Code
    public string $sku = '';     // internal link key (POs / GRN / Zoho) - never shown
    public string $name = '';    // Display Name, chosen by the team
    public string $itemType = 'rm';
    public string $unit = 'NOS';

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

    // Bulk delete (2026-10-13): ticked item ids.
    public array $selected = [];
    public bool $allMatchingSelected = false;
    public array $excludedSelection = [];

    // Export with filters + chosen columns (2026-10-13).
    public array $exportTypes = [];
    public string $exportStatus = 'all';
    public bool $exportOnlyStock = false;
    public array $exportColumns = ['id', 'code', 'name', 'type', 'unit', 'source', 'opening', 'received', 'produced', 'consumed', 'wastage', 'dispatched', 'adjusted', 'stock', 'reserved', 'available', 'location', 'status'];
    public int $perPage = 200;

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedStockSite(): void
    {
        $this->clearSelection();
    }

    public function updatedExportTypes(): void { $this->clearSelection(); }
    public function updatedExportStatus(): void { $this->clearSelection(); }
    public function updatedExportOnlyStock(): void { $this->clearSelection(); }

    private function filteredItemsQuery()
    {
        $canSeeSku = InventoryItem::canSeeSkuName(auth()->user());
        $term = '%'.trim($this->search).'%';

        return InventoryItem::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', $term)->orWhere('code', 'like', $term)
                ->when($canSeeSku, fn ($w) => $w->orWhere('sku', 'like', $term), fn ($w) => $w->orWhere(fn ($n) => $n->whereNull('name')->where('sku', 'like', $term)))
                ->orWhereIn('sku', SkuMaster::where('product_code', 'like', $term)->select('sku'))))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('item_type', $this->typeFilter));
    }

    public function selectVisibleItems(): void
    {
        $this->allMatchingSelected = false;
        $this->excludedSelection = [];
        $this->selected = $this->with()['items']->getCollection()->where('active', true)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function selectAllMatchingItems(): void
    {
        $this->selected = [];
        $this->excludedSelection = [];
        $this->allMatchingSelected = true;
    }

    public function toggleExcludedItem(int $id): void
    {
        $key = (string) $id;
        $this->excludedSelection = in_array($key, $this->excludedSelection, true)
            ? array_values(array_diff($this->excludedSelection, [$key]))
            : [...$this->excludedSelection, $key];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->allMatchingSelected = false;
        $this->excludedSelection = [];
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
                $this->inventory()->adjust($item, (float) $this->adjustQty, $this->adjustReason, auth()->user());
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
        $name = $item->safeDisplayName();
        session()->flash('success', $this->removeOne($item) === 'archived'
            ? "Archived {$name} - it has stock history or is used in a recipe, so it is hidden instead of deleted."
            : "Deleted {$name}.");
    }

    // Same rule for one item or many: unused -> deleted, has history / in a recipe -> archived.
    private function removeOne(InventoryItem $item): string
    {
        $inUse = $item->movements()->exists()
            || InventoryBomLine::where('component_item_id', $item->id)->exists();
        if ($inUse) {
            $item->update(['active' => false]);
            AuditLogger::log('Inventory item archived', $item->sku, $item);
            $result = 'archived';
        } else {
            $item->bomLines()->delete();
            AuditLogger::log('Inventory item deleted', $item->sku);
            $item->delete();
            $result = 'deleted';
        }
        if ($this->editingId === $item->id) {
            $this->resetForm();
        }

        return $result;
    }

    public function bulkRemove(): void
    {
        $done = ['deleted' => 0, 'archived' => 0];
        foreach (InventoryItem::whereIn('id', array_map('intval', $this->selected))->where('active', true)->get() as $item) {
            $done[$this->removeOne($item)]++;
        }
        $this->selected = [];
        session()->flash('success', "Selected items: {$done['deleted']} deleted, {$done['archived']} archived (they have stock history or are in a recipe, so they are hidden instead of deleted).");
    }

    public function selectAllExportColumns(): void
    {
        $this->exportColumns = $this->allExportColumns();
    }

    private function allExportColumns(): array
    {
        $columns = ['id', 'code', 'name', 'type', 'unit', 'source', 'opening', 'received', 'produced', 'consumed', 'wastage', 'dispatched', 'adjusted', 'stock', 'reserved', 'available', 'location', 'status'];
        if (InventoryItem::canSeeSkuName(auth()->user())) {
            $columns[] = 'sku';
        }

        return array_values(array_merge($columns, ItemCustomField::activeFields()->map(fn ($field) => 'cf_'.$field->field_key)->all()));
    }

    private function exportFieldLabels(): array
    {
        return [
            'id' => 'Item ID', 'code' => 'SKU Code', 'name' => 'Display Name', 'sku' => 'Chemical name',
            'type' => 'Type', 'unit' => 'Unit', 'source' => 'Source', 'opening' => 'Opening stock',
            'received' => 'Received (GRN)', 'produced' => 'Produced', 'consumed' => 'Consumed',
            'wastage' => 'Wastage', 'dispatched' => 'Dispatched', 'adjusted' => 'Adjusted',
            'stock' => 'In stock now', 'reserved' => 'Reserved', 'available' => 'Available now',
            'location' => 'Location', 'status' => 'Status',
        ] + ItemCustomField::activeFields()->mapWithKeys(fn ($field) => ['cf_'.$field->field_key => $field->label])->all();
    }

    public function clearExportColumns(): void
    {
        $this->exportColumns = [];
    }

    // Excel export includes every row matching visible and export-specific filters.
    public function exportFiltered()
    {
        $fields = ItemCustomField::activeFields();
        $all = $this->exportFieldLabels();
        if (! InventoryItem::canSeeSkuName(auth()->user())) {
            unset($all['sku']);
        }
        $columns = array_values(array_intersect(array_keys($all), $this->exportColumns)) ?: array_values(array_diff(array_keys($all), ['sku']));
        $items = $this->filteredItemsQuery()
            ->when($this->allMatchingSelected, fn ($q) => $q->whereNotIn('id', array_map('intval', $this->excludedSelection)))
            ->when(! $this->allMatchingSelected && $this->selected, fn ($q) => $q->whereIn('id', array_map('intval', $this->selected)))
            ->when($this->exportTypes, fn ($q) => $q->whereIn('item_type', $this->exportTypes))
            ->when($this->exportStatus !== 'all', fn ($q) => $q->where('active', $this->exportStatus === 'active'))
            ->orderBy('item_type')->orderBy('sku')->get();
        $inventory = $this->inventory();
        $balances = $inventory->balances($items);
        $reserved = $inventory->reservedForOpenShipments($items, $inventory->readSite());
        if ($this->exportOnlyStock) {
            $items = $items->filter(fn ($i) => abs($balances[$i->id]['final'] ?? 0) > 0.0005)->values();
        }
        $codes = SkuMaster::whereNotNull('product_code')->pluck('product_code', 'sku')->mapWithKeys(fn ($c, $sku) => [mb_strtolower($sku) => $c]);
        $zoho = SkuMaster::whereNotNull('zoho_item_id')->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip();
        $erp = SkuMaster::pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip();
        $locationLabel = $this->stockSiteView()['stockSiteName'] ?? 'All locations available (separate warehouse rows)';
        $rows = [array_map(fn ($c) => $all[$c], $columns)];
        foreach ($items as $item) {
            $rows[] = array_map(fn ($c) => match ($c) {
                'id' => (string) $item->id,
                'code' => $item->code ?: ($codes[mb_strtolower($item->sku)] ?? ''),
                'name' => $item->safeDisplayName(),
                'sku' => $item->sku,
                'type' => $item->typeLabel(),
                'unit' => $item->unit,
                'opening' => (string) ($balances[$item->id]['opening'] ?? 0),
                'received' => (string) ($balances[$item->id]['received'] ?? 0),
                'produced' => (string) ($balances[$item->id]['produced'] ?? 0),
                'consumed' => (string) ($balances[$item->id]['consumed'] ?? 0),
                'wastage' => (string) ($balances[$item->id]['wastage'] ?? 0),
                'dispatched' => (string) ($balances[$item->id]['dispatched'] ?? 0),
                'adjusted' => (string) ($balances[$item->id]['adjusted'] ?? 0),
                'stock' => (string) round($balances[$item->id]['final'] ?? 0, 3),
                'reserved' => (string) round($reserved[$item->id] ?? 0, 3),
                'available' => (string) round(($balances[$item->id]['final'] ?? 0) - ($reserved[$item->id] ?? 0), 3),
                'source' => $zoho->has(mb_strtolower($item->sku)) ? 'Zoho' : ($erp->has(mb_strtolower($item->sku)) ? 'ERP Items' : 'GRN / added here'),
                'location' => $locationLabel,
                'status' => $item->active ? 'Active' : 'Archived',
                default => (string) \App\Support\CustomFields::display($fields->firstWhere('field_key', substr($c, 3)), $item->custom_fields[substr($c, 3)] ?? null),
            }, $columns);
        }
        $bytes = \App\Support\HtmlToXlsx::fromRows($rows);

        return response()->streamDownload(fn () => print ($bytes), 'items-export-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function restoreItem(int $id): void
    {
        $item = InventoryItem::findOrFail($id);
        $item->update(['active' => true]);
        session()->flash('success', "Restored {$item->safeDisplayName()}.");
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
        $query = $this->filteredItemsQuery();
        $total = (clone $query)->count();
        $items = $query
            ->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')
            ->paginate($this->perPage);

        // Suggest SKU names already used by POs / GRN / SKU Master so stock links up.
        $known = collect()
            ->merge(SkuMaster::pluck('sku'))
            ->merge(LedgerEntry::distinct()->pluck('sku'))
            ->merge(PurchaseOrderLine::distinct()->pluck('product'))
            ->filter()->unique()->diff(InventoryItem::pluck('sku'))->sort()->values();

        return [
            ...$this->stockSiteView(),
            'canSeeSku' => $canSeeSku,
            'editingItem' => $this->editingId ? InventoryItem::with(['createdBy', 'updatedBy'])->find($this->editingId) : null,
            'editingStock' => $this->editingId ? $this->inventory()->balances(InventoryItem::whereKey($this->editingId)->get())[$this->editingId]['final'] ?? 0 : null,
            'editingIsZoho' => $this->editingId && SkuMaster::where('sku', $this->sku)->whereNotNull('zoho_item_id')->exists(),
            'items' => $items,
            'total' => $total,
            'balances' => $this->inventory()->balances($items->getCollection()),
            'types' => InventoryItem::TYPES,
            'units' => \App\Support\Units::options(),
            'customFields' => ItemCustomField::activeFields(),
            'exportFields' => $this->exportFieldLabels(),
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
    @include('partials.stock-site-picker', ['required' => false])

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm" style="color: var(--text-secondary);">Export uses the current filters across every page.</div>
        <button type="button" wire:click="exportFiltered" wire:loading.attr="disabled" wire:target="exportFiltered" class="inline-flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50" style="background: var(--brand);">
            <span wire:loading.remove wire:target="exportFiltered">{{ $allMatchingSelected || $selected ? 'Export Selected' : 'Export Filtered' }}</span>
            <span wire:loading wire:target="exportFiltered" role="status">Preparing export…</span>
        </button>
        <a href="{{ route('inventory.items.create') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2" style="background: var(--brand);">
            <span aria-hidden="true" class="mr-2 text-lg leading-none">+</span>Add item
        </a>
    </div>

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

    <details class="rounded-2xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);" open>
        <summary class="text-base font-semibold cursor-pointer" style="color: var(--text-primary);">Export options · choose extra filters and columns</summary>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-3 text-sm">
            <div>
                <div class="text-xs font-semibold mb-1" style="color: var(--text-muted);">Types (none ticked = all)</div>
                @foreach ($types as $value => $label)<label class="flex items-center gap-2"><input type="checkbox" wire:model="exportTypes" value="{{ $value }}"> {{ $label }}</label>@endforeach
            </div>
            <div class="space-y-2">
                <div class="text-xs font-semibold" style="color: var(--text-muted);">Status</div>
                <select wire:model="exportStatus" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="all">Active and archived</option><option value="active">Active only</option><option value="archived">Archived only</option></select>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="exportOnlyStock"> Only items with stock</label>
                <div class="text-xs" style="color: var(--text-muted);">The search box above and the Site choice also apply.</div>
            </div>
            <div>
                <div class="text-xs font-semibold mb-1" style="color: var(--text-muted);">Columns</div>
                <div class="mb-2 flex gap-3">
                    <button type="button" wire:click="selectAllExportColumns" class="underline font-medium" style="color: var(--brand);">Select all fields</button>
                    <button type="button" wire:click="clearExportColumns" class="underline" style="color: var(--text-secondary);">Clear selection</button>
                </div>
                @foreach ($exportFields as $key => $label)
                    @if ($key !== 'sku' || $canSeeSku)<label class="flex items-center gap-2"><input type="checkbox" wire:model="exportColumns" value="{{ $key }}"> {{ $label }}</label>@endif
                @endforeach
            </div>
        </div>
        <p class="mt-3 text-xs" style="color: var(--text-muted);">The visible search, type and site filters always apply. Extra export filters narrow the export further; export is independent of how many rows are currently shown on screen.</p>
    </details>

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
                <span class="text-xs" style="color: var(--text-muted);">Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of {{ $total }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <button type="button" wire:click="selectVisibleItems" class="underline" style="color:var(--brand);">Select this page</button>
                <button type="button" wire:click="selectAllMatchingItems" class="underline" style="color:var(--brand);">Select all matching filters ({{ $total }})</button>
                @if ($allMatchingSelected || $selected)<button type="button" wire:click="clearSelection" class="underline">Clear selection</button>@endif
                @if ($allMatchingSelected)<span>{{ max(0, $total - count($excludedSelection)) }} selected across pages</span>@elseif($selected)<span>{{ count($selected) }} selected</span>@endif
            </div>
            @if ($selected && ! $allMatchingSelected)
                <div class="flex flex-wrap items-center gap-3 mb-3 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--status-warning); background: var(--status-warning-bg);">
                    <span class="font-semibold">{{ count($selected) }} selected</span>
                    <button type="button" wire:click="bulkRemove" wire:confirm="Remove {{ count($selected) }} items? Items with stock history or in a recipe are archived instead of deleted." class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background: var(--status-critical);">Delete selected</button>
                    <button type="button" wire:click="$set('selected', [])" class="text-xs underline">Clear</button>
                </div>
            @endif
            <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm min-w-[760px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="pl-4 py-3 w-8"><input type="checkbox" aria-label="Select all shown" x-on:change="$event.target.checked ? $wire.call('selectVisibleItems') : $wire.call('clearSelection')"></th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Source</th><th class="px-4 py-3 text-right">In stock now</th>@foreach ($customFields as $field)<th class="px-4 py-3">{{ $field->label }}</th>@endforeach<th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="inv-item-{{ $item->id }}" style="border-top: 1px solid var(--border);{{ $item->active ? "" : " opacity: .55;" }}">
                                <td class="pl-4 py-3">@if ($item->active)@if($allMatchingSelected)<input type="checkbox" @checked(!in_array((string) $item->id, $excludedSelection, true)) wire:click="toggleExcludedItem({{ $item->id }})" aria-label="Select {{ $item->safeDisplayName() }}">@else<input type="checkbox" wire:model.live="selected" value="{{ $item->id }}" aria-label="Select {{ $item->safeDisplayName() }}">@endif @endif</td>
                                <td class="px-4 py-3"><a href="{{ route('inventory.items.show', $item) }}" wire:navigate class="text-left font-medium hover:underline" style="color: var(--text-primary);" title="Open full item and stock history">{{ $item->visibleNameFor(auth()->user()) }}</a></td>
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
                                    <a href="{{ route('inventory.items.edit', $item) }}" wire:navigate class="text-xs font-semibold hover:underline" style="color: var(--brand);">Edit</a>
                                    <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="Remove {{ $item->safeDisplayName() }}? If it already has stock history it will be archived instead of deleted." class="text-xs font-semibold ml-3" style="color: var(--status-critical);">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ 8 + $customFields->count() }}" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet. Add items in SKU Master (Admin &gt; SKU Master) or receive them through GRN - they will appear here automatically.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $items->links() }}</div>

        </div>
    </div>
</div>
