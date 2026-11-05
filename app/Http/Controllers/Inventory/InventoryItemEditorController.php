<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\ItemCustomField;
use App\Models\SkuMaster;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Support\CustomFields;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryItemEditorController
{
    public function create(): View
    {
        return $this->form(null, null);
    }

    public function edit(InventoryItem $inventoryItem): View
    {
        return $this->form($inventoryItem, SkuMaster::where('sku', $inventoryItem->sku)->first());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $item = DB::transaction(function () use ($data, $request): InventoryItem {
            $item = InventoryItem::create([
                'sku' => trim($data['name']),
                'code' => $data['code'] ?: null,
                'name' => trim($data['name']),
                'masked_name' => trim((string) ($data['masked_name'] ?? '')) ?: null,
                'alternative_name' => trim((string) ($data['alternative_name'] ?? '')) ?: null,
                'item_type' => $data['item_type'],
                'unit' => $data['unit'],
                'opening_qty' => 0,
                'active' => (bool) $data['active'],
                'custom_fields' => $this->customValues($data, null) ?: null,
            ]);

            $this->saveSkuMaster($item, $data, $request->user()?->isAdmin() ?? false);

            return $item;
        });

        AuditLogger::log('Inventory item added', $this->label($item).' · '.$item->typeLabel(), $item);
        session()->flash('success', 'Item added.');

        return redirect()->route('inventory.items.show', $item);
    }

    public function update(Request $request, InventoryItem $inventoryItem, InventoryService $inventory): RedirectResponse
    {
        $data = $this->validated($request, $inventoryItem);
        DB::transaction(function () use ($inventoryItem, $data, $request, $inventory): void {
            $inventoryItem->update([
                'code' => $data['code'] ?: null,
                'name' => trim($data['name']),
                'masked_name' => trim((string) ($data['masked_name'] ?? '')) ?: null,
                'alternative_name' => trim((string) ($data['alternative_name'] ?? '')) ?: null,
                // The SKU is the historical PO/GRN/Zoho key and is intentionally immutable.
                'item_type' => $data['item_type'],
                'unit' => $data['unit'],
                'active' => (bool) $data['active'],
                'custom_fields' => $this->customValues($data, $inventoryItem) ?: null,
            ]);

            $this->saveSkuMaster($inventoryItem, $data, $request->user()?->isAdmin() ?? false);

            if ((float) ($data['adjust_qty'] ?? 0) !== 0.0) {
                $inventory->adjust($inventoryItem, (float) $data['adjust_qty'], $data['adjust_reason'], $request->user());
            }
        });

        AuditLogger::log(
            'Inventory item updated',
            $this->label($inventoryItem).' · '.$inventoryItem->typeLabel().((float) ($data['adjust_qty'] ?? 0) !== 0.0 ? ' · stock '.$data['adjust_qty'].' ('.$data['adjust_reason'].')' : ''),
            $inventoryItem,
        );
        session()->flash('success', 'Item changes saved.');

        return redirect()->route('inventory.items.show', $inventoryItem);
    }

    private function form(?InventoryItem $item, ?SkuMaster $skuMaster): View
    {
        return view('inventory.item-form', [
            'item' => $item,
            'skuMaster' => $skuMaster,
            'customFields' => ItemCustomField::activeFields(),
            'types' => InventoryItem::TYPES,
            'units' => array_map('strtoupper', Units::options()),
            'canSeePrices' => auth()->user()?->isAdmin() ?? false,
            'editingStock' => $item ? app(InventoryService::class)->balances(collect([$item]))[$item->id]['final'] ?? 0 : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?InventoryItem $item = null): array
    {
        $request->merge(['unit' => strtoupper(trim((string) $request->input('unit', '')))]);
        $rules = [
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'sku')->ignore($item?->id)],
            'masked_name' => ['nullable', 'string', 'max:255'],
            'alternative_name' => ['nullable', 'string', 'max:255'],
            'item_type' => ['required', Rule::in(array_keys(InventoryItem::TYPES))],
            'unit' => ['required', 'string', 'max:20', Rule::in(array_merge(array_map('strtoupper', Units::options()), [$item?->unit]))],
            'active' => ['required', 'boolean'],
            'category' => ['nullable', 'string', 'max:255'],
            'default_bin' => ['nullable', 'string', 'max:255'],
            'mapped' => ['required', 'boolean'],
            'product_owner' => ['nullable', 'string', 'max:255'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'sales_start_date' => ['nullable', 'date'],
            'sales_end_date' => ['nullable', 'date'],
            'support_start_date' => ['nullable', 'date'],
            'support_end_date' => ['nullable', 'date'],
            'tax' => ['nullable', 'numeric', 'between:0,100'],
            'commission_rate' => ['nullable', 'numeric', 'min:0'],
            'taxable' => ['required', 'boolean'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'handler' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'zoho_hsn' => ['nullable', 'string', 'max:80'],
            'intra_state_tax_name' => ['nullable', 'string', 'max:80'],
            'intra_state_tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'intra_state_tax_type' => ['nullable', 'string', 'max:80'],
            'inter_state_tax_name' => ['nullable', 'string', 'max:80'],
            'inter_state_tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'inter_state_tax_type' => ['nullable', 'string', 'max:80'],
            'taxability_type' => ['nullable', 'string', 'max:80'],
            'tax_exemption_reason' => ['nullable', 'string', 'max:255'],
            'adjust_qty' => ['nullable', 'numeric', 'required_with:adjust_reason'],
            'adjust_reason' => [Rule::requiredIf(fn () => (float) $request->input('adjust_qty', 0) !== 0.0), 'nullable', 'string', 'max:250'],
        ];

        if ($request->user()?->isAdmin()) {
            $rules['unit_price'] = ['nullable', 'numeric', 'min:0'];
        }

        $fields = ItemCustomField::activeFields();
        $rules += CustomFields::rules($fields, 'custom_values');

        $data = $request->validate($rules, [
            'name.unique' => 'An item with this name already exists.',
            'adjust_reason.required' => 'Add a reason for the stock change.',
        ], CustomFields::attributeNames($fields, 'custom_values'));
        $data['category'] = trim((string) ($data['category'] ?? '')) ?: InventoryItem::TYPES[$data['item_type']];
        $data['unit'] = strtoupper($data['unit']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function customValues(array $data, ?InventoryItem $item): array
    {
        $active = ItemCustomField::activeFields();
        $inactiveKeys = ItemCustomField::where('active', false)->pluck('field_key')->all();
        $previous = $item?->custom_fields ?? [];
        $preserved = array_intersect_key($previous, array_flip($inactiveKeys));

        return array_merge($preserved, CustomFields::clean($active, $data['custom_values'] ?? []));
    }

    /** @param array<string, mixed> $data */
    private function saveSkuMaster(InventoryItem $item, array $data, bool $canSeePrices): void
    {
        $master = SkuMaster::firstOrNew(['sku' => $item->sku]);
        $editable = [
            'category', 'unit', 'default_bin', 'mapped', 'product_owner', 'product_code', 'active', 'vendor_name',
            'manufacturer', 'sales_start_date', 'sales_end_date', 'support_start_date', 'support_end_date', 'tax',
            'commission_rate', 'taxable', 'handler', 'reorder_level', 'description', 'zoho_hsn',
            'intra_state_tax_name', 'intra_state_tax_rate', 'intra_state_tax_type',
            'inter_state_tax_name', 'inter_state_tax_rate', 'inter_state_tax_type', 'taxability_type', 'tax_exemption_reason',
        ];
        $values = array_intersect_key($data, array_flip($editable));
        $values['product_code'] = $data['code'] ?: null;
        $values['category'] = $data['category'];
        $values['unit'] = $data['unit'];
        $values['active'] = (bool) $data['active'];
        if ($canSeePrices && array_key_exists('unit_price', $data)) {
            $values['unit_price'] = $data['unit_price'];
        }

        $master->fill($values)->save();
    }

    private function label(InventoryItem $item): string
    {
        return trim(implode(' · ', array_filter([$item->code, $item->safeDisplayName()])), ' ·') ?: 'item';
    }
}
