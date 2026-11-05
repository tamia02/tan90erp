<x-app-layout>
    @php
        $value = fn (string $field, mixed $fallback = null) => old($field, $skuMaster?->{$field} ?? $fallback);
        $dateValue = fn (string $field) => old($field, $skuMaster?->{$field}?->format('Y-m-d'));
        $input = 'mt-1 w-full rounded-xl border px-3 py-2.5 text-sm focus:outline-none focus:ring-2';
        $inputStyle = 'border-color: var(--border); background: var(--surface-1); color: var(--text-primary);';
        $labelStyle = 'color: var(--text-secondary);';
        $sectionStyle = 'background: var(--surface-3); border-color: var(--border);';
    @endphp

    <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:px-8">
        @include('partials.inventory-tabs', [
            'current' => $item ? 'inventory.items.show' : 'inventory.items',
            'title' => $item ? 'Edit item' : 'Add item',
            'subtitle' => 'Update item master details, GST, supplier, custom fields, and item status.',
        ])

        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold" style="color: var(--text-primary);">{{ $item ? 'Edit '.$item->visibleNameFor(auth()->user()) : 'Add an item' }}</h1>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">This form is a dedicated page. The item’s SKU link and stock history stay intact.</p>
            </div>
            <div class="flex gap-2">
                @if ($item)
                    <a href="{{ route('inventory.items.show', $item) }}" wire:navigate class="rounded-xl border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Cancel</a>
                @else
                    <a href="{{ route('inventory.items') }}" wire:navigate class="rounded-xl border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Cancel</a>
                @endif
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="mb-5 rounded-xl border p-4 text-sm" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                Please fix the highlighted fields and save again.
            </div>
        @endif

        <form method="POST" action="{{ $item ? route('inventory.items.update', $item) : route('inventory.items.store') }}" class="space-y-5">
            @csrf
            @if ($item) @method('PUT') @endif

            <section class="rounded-2xl border p-5 sm:p-6" style="{{ $sectionStyle }}">
                <div class="mb-4">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">Inventory item</h2>
                    <p class="mt-1 text-sm" style="color: var(--text-muted);">Core fields used in the item list, purchase orders, and stock flows.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">SKU Code
                        <input name="code" value="{{ old('code', $item?->code ?: $skuMaster?->product_code) }}" maxlength="80" class="{{ $input }}" style="{{ $inputStyle }}">
                        @error('code') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Display Name <span aria-hidden="true">*</span>
                        <input name="name" required value="{{ old('name', $item?->name) }}" maxlength="255" class="{{ $input }}" style="{{ $inputStyle }}">
                        @error('name') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Masked Name
                        <input name="masked_name" value="{{ old('masked_name', $item?->masked_name) }}" maxlength="255" class="{{ $input }}" style="{{ $inputStyle }}" placeholder="Name to show on POs and invoices">
                        @error('masked_name') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Alternative Name
                        <input name="alternative_name" value="{{ old('alternative_name', $item?->alternative_name) }}" maxlength="255" class="{{ $input }}" style="{{ $inputStyle }}" placeholder="Fallback name for documents">
                        @error('alternative_name') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Item type <span aria-hidden="true">*</span>
                        <select name="item_type" required class="{{ $input }}" style="{{ $inputStyle }}">
                            @foreach ($types as $key => $label)<option value="{{ $key }}" @selected(old('item_type', $item?->item_type ?? 'rm') === $key)>{{ $label }}</option>@endforeach
                        </select>
                        @error('item_type') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Unit <span aria-hidden="true">*</span>
                        <select name="unit" required class="{{ $input }}" style="{{ $inputStyle }}">
                            @foreach ($units as $unit)<option value="{{ $unit }}" @selected(old('unit', $item?->unit ?? $skuMaster?->unit ?? 'NOS') === $unit)>{{ $unit }}</option>@endforeach
                            @if ($item && ! in_array($item->unit, $units, true))<option value="{{ $item->unit }}" selected>{{ $item->unit }} · existing unit</option>@endif
                        </select>
                        @error('unit') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Item status
                        <select name="active" required class="{{ $input }}" style="{{ $inputStyle }}">
                            <option value="1" @selected((string) old('active', (int) ($item?->active ?? true)) === '1')>Active</option>
                            <option value="0" @selected((string) old('active', (int) ($item?->active ?? true)) === '0')>Archived</option>
                        </select>
                        @error('active') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    @foreach ($customFields as $field)
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">{{ $field->label }}{{ $field->required ? ' *' : '' }}
                            @include('partials.custom-field-input', ['field' => $field, 'wire' => null, 'name' => 'custom_values['.$field->field_key.']', 'value' => old('custom_values.'.$field->field_key, $item?->custom_fields[$field->field_key] ?? '')])
                            @error('custom_values.'.$field->field_key) <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                @if ($item && $item->created_at)
                    <p class="mt-4 border-t pt-3 text-xs" style="border-color: var(--border); color: var(--text-muted);">Added {{ $item->created_at->format('d M Y, H:i') }} by {{ $item->createdBy?->name ?? 'Zoho sync / system' }} · Last updated {{ $item->updated_at?->format('d M Y, H:i') }} by {{ $item->updatedBy?->name ?? 'Zoho sync / system' }}</p>
                @endif
            </section>

            <section class="rounded-2xl border p-5 sm:p-6" style="{{ $sectionStyle }}">
                <div class="mb-4">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">Catalog and supplier details</h2>
                    <p class="mt-1 text-sm" style="color: var(--text-muted);">Commercial and operational master fields linked to this item’s SKU.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        'category' => ['Category', $item ? $item->typeLabel() : ''],
                        'default_bin' => ['Default bin', ''],
                        'product_owner' => ['Product owner', ''],
                        'vendor_name' => ['Preferred supplier', ''],
                        'manufacturer' => ['Brand / manufacturer', ''],
                        'handler' => ['Handler', ''],
                        'reorder_level' => ['Reorder level', ''],
                    ] as $field => [$label, $fallback])
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">{{ $label }}
                            <input name="{{ $field }}" value="{{ $value($field, $fallback) }}" @if ($field === 'reorder_level') type="number" min="0" step="1" @else type="text" @endif class="{{ $input }}" style="{{ $inputStyle }}">
                            @error($field) <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Mapped
                        <select name="mapped" required class="{{ $input }}" style="{{ $inputStyle }}">
                            <option value="1" @selected((string) old('mapped', (int) ($skuMaster?->mapped ?? true)) === '1')>Yes</option>
                            <option value="0" @selected((string) old('mapped', (int) ($skuMaster?->mapped ?? true)) === '0')>No</option>
                        </select>
                        @error('mapped') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    @foreach ([
                        'sales_start_date' => 'Sales start date', 'sales_end_date' => 'Sales end date',
                        'support_start_date' => 'Support start date', 'support_end_date' => 'Support end date',
                    ] as $field => $label)
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">{{ $label }}
                            <input type="date" name="{{ $field }}" value="{{ $dateValue($field) }}" class="{{ $input }}" style="{{ $inputStyle }}">
                            @error($field) <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                    @if ($canSeePrices)
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">Rate
                            <input type="number" min="0" step="0.01" name="unit_price" value="{{ old('unit_price', $skuMaster?->unit_price) }}" class="{{ $input }}" style="{{ $inputStyle }}">
                            @error('unit_price') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endif
                    <label class="text-sm font-medium sm:col-span-2 lg:col-span-3" style="{{ $labelStyle }}">Description
                        <textarea name="description" rows="4" maxlength="20000" class="{{ $input }}" style="{{ $inputStyle }}">{{ $value('description') }}</textarea>
                        @error('description') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border p-5 sm:p-6" style="{{ $sectionStyle }}">
                <div class="mb-4">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">GST details</h2>
                    <p class="mt-1 text-sm" style="color: var(--text-muted);">HSN/SAC, taxability, and intra-state / inter-state tax setup.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">HSN / SAC
                        <input name="zoho_hsn" value="{{ $value('zoho_hsn') }}" maxlength="80" class="{{ $input }}" style="{{ $inputStyle }}">
                        @error('zoho_hsn') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Taxable
                        <select name="taxable" required class="{{ $input }}" style="{{ $inputStyle }}">
                            <option value="1" @selected((string) old('taxable', (int) ($skuMaster?->taxable ?? true)) === '1')>Yes</option>
                            <option value="0" @selected((string) old('taxable', (int) ($skuMaster?->taxable ?? true)) === '0')>No</option>
                        </select>
                        @error('taxable') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Tax rate (%)
                        <input type="number" min="0" max="100" step="0.01" name="tax" value="{{ $value('tax') }}" class="{{ $input }}" style="{{ $inputStyle }}">
                        @error('tax') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-medium" style="{{ $labelStyle }}">Commission rate
                        <input type="number" min="0" step="0.01" name="commission_rate" value="{{ $value('commission_rate') }}" class="{{ $input }}" style="{{ $inputStyle }}">
                        @error('commission_rate') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    @foreach ([
                        'intra_state_tax_name' => 'Intra-state tax name', 'intra_state_tax_rate' => 'Intra-state tax rate (%)', 'intra_state_tax_type' => 'Intra-state tax type',
                        'inter_state_tax_name' => 'Inter-state tax name', 'inter_state_tax_rate' => 'Inter-state tax rate (%)', 'inter_state_tax_type' => 'Inter-state tax type',
                        'taxability_type' => 'Taxability type', 'tax_exemption_reason' => 'Exemption reason',
                    ] as $field => $label)
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">{{ $label }}
                            <input name="{{ $field }}" value="{{ $value($field) }}" @if (str_ends_with($field, '_rate')) type="number" min="0" max="100" step="0.001" @else type="text" @endif class="{{ $input }}" style="{{ $inputStyle }}">
                            @error($field) <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
            </section>

            @if ($item)
                <section class="rounded-2xl border p-5 sm:p-6" style="{{ $sectionStyle }}">
                    <div class="mb-4">
                        <h2 class="text-lg font-bold" style="color: var(--text-primary);">Stock adjustment</h2>
                        <p class="mt-1 text-sm" style="color: var(--text-muted);">Current stock: {{ rtrim(rtrim(number_format((float) $editingStock, 3, '.', ''), '0'), '.') ?: '0' }} {{ $item->unit }}. Changes are recorded as stock movements; opening and historical quantities are not overwritten.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="text-sm font-medium" style="{{ $labelStyle }}">Change quantity (+ or −)
                            <input type="number" step="0.001" name="adjust_qty" value="{{ old('adjust_qty') }}" placeholder="e.g. 25 or -10" class="{{ $input }}" style="{{ $inputStyle }}">
                            @error('adjust_qty') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="text-sm font-medium sm:col-span-2" style="{{ $labelStyle }}">Reason
                            <input name="adjust_reason" value="{{ old('adjust_reason') }}" maxlength="250" placeholder="Physical count, damage, correction…" class="{{ $input }}" style="{{ $inputStyle }}">
                            @error('adjust_reason') <span class="mt-1 block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>
            @endif

            @if ($item)
                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('inventory.items.show', $item) }}" class="rounded-xl border px-5 py-3 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Cancel</a>
                    <button type="submit" class="rounded-xl px-5 py-3 text-sm font-semibold text-white" style="background: var(--brand);">Save item changes</button>
                </div>
            @else
                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('inventory.items') }}" class="rounded-xl border px-5 py-3 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Cancel</a>
                    <button type="submit" class="rounded-xl px-5 py-3 text-sm font-semibold text-white" style="background: var(--brand);">Add item</button>
                </div>
            @endif
        </form>
    </div>
</x-app-layout>
