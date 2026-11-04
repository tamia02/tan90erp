<x-app-layout>
    @php
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0';
        $rate = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.').'%';
        $stocks = [
            ['Opening', $balances['opening'] ?? 0, 'baseline'],
            ['Received (GRN)', $balances['received'] ?? 0, 'received'],
            ['Produced', $balances['produced'] ?? 0, 'produced'],
            ['Adjusted', $balances['adjusted'] ?? 0, 'adjusted'],
            ['Consumed', -($balances['consumed'] ?? 0), 'consumed'],
            ['Wastage', -($balances['wastage'] ?? 0), 'wastage'],
            ['Dispatched', -($balances['dispatched'] ?? 0), 'dispatched'],
            ['On-hand stock', $balances['final'] ?? 0, 'final'],
            ['Reserved (open shipments)', -($balances['reserved'] ?? 0), 'reserved'],
            ['Available now', $balances['available'] ?? $balances['final'] ?? 0, 'available'],
        ];
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        @include('partials.inventory-tabs', [
            'current' => 'inventory.items.show',
            'title' => 'Item and stock history',
            'subtitle' => 'A complete item record with current stock, GST, purchase references, recipes, movement history, and audit activity.',
        ])

        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('inventory.items') }}" wire:navigate class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">← Items</a>
                <a href="{{ route('inventory.stock') }}" wire:navigate class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Inventory summary</a>
            </div>
            <a href="{{ route('inventory.items.edit', $item) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-white" style="background: var(--brand);">Edit item</a>
        </div>

        <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold" style="color: var(--text-primary);">Stock history: {{ $item->visibleNameFor(auth()->user()) }}</h1>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $item->active ? 'var(--status-good-bg)' : 'var(--surface-2)' }}; color: {{ $item->active ? 'var(--status-good)' : 'var(--text-muted)' }};">{{ $item->active ? 'Active' : 'Archived' }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm" style="color: var(--text-secondary);">
                        @if ($item->code)<span>Code: <strong>{{ $item->code }}</strong></span>@endif
                        <span>{{ $item->typeLabel() }}</span>
                        <span>Unit: {{ $item->unit }}</span>
                        @if ($canSeeSku)<span>SKU: {{ $item->sku }}</span>@endif
                    </div>
                </div>
                <div class="min-w-48 rounded-xl border px-4 py-3 text-right" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">{{ request()->filled('warehouse_id') ? 'Available at selected warehouse' : (request()->filled('location_id') ? 'Available across warehouses at selected location' : 'Available across visible warehouses') }}</div>
                    <div class="mt-1 text-2xl font-bold" style="color: var(--text-primary);">{{ $qty($balances['available'] ?? $balances['final'] ?? 0) }} <span class="text-sm">{{ $item->unit }}</span></div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-lg font-bold" style="color: var(--text-primary);">Stock by warehouse</h2>
            <p class="mt-1 text-xs" style="color: var(--text-muted);">Each warehouse is calculated separately. Your account only receives warehouses at locations in its access scope.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[680px] text-sm">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="py-2 pr-3">Warehouse Code</th><th class="py-2 pr-3 text-right">Opening</th><th class="py-2 pr-3 text-right">Received</th><th class="py-2 pr-3 text-right">Produced</th><th class="py-2 pr-3 text-right">Dispatched</th><th class="py-2 pr-3 text-right">On hand</th><th class="py-2 text-right">Available</th></tr></thead>
                    <tbody>
                        @forelse ($locationBalances as $siteRow)
                            @php($siteBalance = $siteRow['balance'])
                            <tr style="border-top:1px solid var(--border);"><td class="py-2 pr-3"><div class="font-semibold">{{ $siteRow['warehouse']->code }}</div><div class="text-xs" style="color:var(--text-muted);">{{ $siteRow['warehouse']->name }} · {{ $siteRow['warehouse']->location_name }}</div></td><td class="py-2 pr-3 text-right">{{ $qty($siteBalance['opening']) }}</td><td class="py-2 pr-3 text-right">{{ $qty($siteBalance['received']) }}</td><td class="py-2 pr-3 text-right">{{ $qty($siteBalance['produced']) }}</td><td class="py-2 pr-3 text-right">{{ $qty($siteBalance['dispatched']) }}</td><td class="py-2 pr-3 text-right font-semibold">{{ $qty($siteBalance['final']) }}</td><td class="py-2 text-right font-bold">{{ $qty($siteBalance['available']) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="py-4 text-center" style="color:var(--text-muted);">No locations are assigned to this account.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid grid-cols-2 gap-3 mb-5 sm:grid-cols-4 xl:grid-cols-8" aria-label="Stock balance breakdown">
            @foreach ($stocks as [$label, $value, $key])
                <div class="rounded-xl border p-3" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="text-[11px] font-semibold uppercase tracking-wide" style="color: var(--text-muted);">{{ $label }}</div>
                    <div class="mt-1 text-lg font-bold" style="color: {{ $key === 'final' ? 'var(--brand)' : 'var(--text-primary)' }};">{{ $qty($value) }}</div>
                    <div class="text-xs" style="color: var(--text-muted);">{{ $item->unit }}</div>
                </div>
            @endforeach
        </section>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-lg font-bold" style="color: var(--text-primary);">Item master and GST</h2>
                @if ($skuMaster)
                    <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><dt class="text-xs" style="color: var(--text-muted);">HSN / SAC</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->zoho_hsn ?: '—' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Taxable</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->taxable ? 'Yes' : 'No' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Intra-state GST</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->intra_state_tax_name ?: '—' }} · {{ $rate($skuMaster->intra_state_tax_rate) }} · {{ $skuMaster->intra_state_tax_type ?: '—' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Inter-state GST</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->inter_state_tax_name ?: '—' }} · {{ $rate($skuMaster->inter_state_tax_rate) }} · {{ $skuMaster->inter_state_tax_type ?: '—' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Taxability type</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->taxability_type ?: '—' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Exemption reason</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->tax_exemption_reason ?: '—' }}</dd></div>
                        <div><dt class="text-xs" style="color: var(--text-muted);">Brand / manufacturer</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ collect([$skuMaster->manufacturer])->filter()->join(' · ') ?: '—' }}</dd></div>
                        @if ($canSeePrices)<div><dt class="text-xs" style="color: var(--text-muted);">Rate</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $skuMaster->unit_price !== null ? number_format((float) $skuMaster->unit_price, 2) : '—' }}</dd></div>@endif
                        @if ($skuMaster->description)<div class="sm:col-span-2"><dt class="text-xs" style="color: var(--text-muted);">Description</dt><dd class="whitespace-pre-line text-sm" style="color: var(--text-primary);">{{ $skuMaster->description }}</dd></div>@endif
                    </dl>
                @else
                    <p class="mt-3 text-sm" style="color: var(--text-muted);">No linked SKU master record.</p>
                @endif
            </section>

            @if ($supplier)
                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">Preferred supplier</h2>
                    <div class="mt-3 font-semibold" style="color: var(--text-primary);">{{ $supplier->vendor_name }}</div>
                    <div class="mt-2 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2" style="color: var(--text-secondary);">
                        <div>GSTIN: {{ $supplier->gst_number ?: '—' }}</div>
                        <div>Phone: {{ $supplier->contact_phone ?: '—' }}</div>
                        <div>Email: {{ $supplier->contact_email ?: '—' }}</div>
                        <div>Payment terms: {{ $supplier->payment_terms ?: '—' }}</div>
                        <div class="sm:col-span-2">Address: {{ collect([$supplier->address_building, $supplier->address_street, $supplier->address_city, $supplier->address_state, $supplier->address_zip, $supplier->address_country])->filter()->join(', ') ?: '—' }}</div>
                        @if ($supplier->gst_treatment)<div>GST treatment: {{ $supplier->gst_treatment }}</div>@endif
                        @if ($supplier->msme_number)<div>MSME/Udyam: {{ $supplier->msme_number }} · {{ $supplier->msme_type }}</div>@endif
                    </div>
                </section>
            @endif

            @if ($sourceDetails->isNotEmpty())
                <section class="rounded-2xl border p-5 xl:col-span-2" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">Imported workbook details</h2>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);">Source values from the supplied workbook rows, kept with their file and source row number.</p>
                    <div class="mt-3 space-y-3">
                        @foreach ($sourceDetails as $source)
                            <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                                <div class="mb-2 text-xs font-semibold" style="color: var(--brand);">{{ $source['file'] }} · row {{ $source['row'] }}</div>
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($source['fields'] as $label => $value)
                                        <div><dt class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</dt><dd class="break-words text-sm" style="color: var(--text-primary);">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd></div>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-2xl border p-5 xl:col-span-2" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-lg font-bold" style="color: var(--text-primary);">Stock movement history <span class="text-sm font-normal" style="color: var(--text-muted);">({{ $history->count() }} entries)</span></h2>
                @if ($historyKind)<p class="mt-1 text-xs" style="color: var(--text-muted);">Filtered to {{ ucfirst($historyKind) }} movements. <a href="{{ route('inventory.items.show', $item) }}" wire:navigate class="underline" style="color: var(--brand);">Show all history</a></p>@endif
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[650px] text-sm">
                        <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="py-2 pr-3">Date / time</th><th class="py-2 pr-3">Warehouse Code</th><th class="py-2 pr-3">Movement</th><th class="py-2 text-right">Quantity</th></tr></thead>
                        <tbody>
                            @forelse ($history as $movement)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="whitespace-nowrap py-2 pr-3">{{ $movement['at'] ? \Illuminate\Support\Carbon::parse($movement['at'])->format('d M Y, H:i') : '—' }}</td>
                                    <td class="py-2 pr-3">{{ $movement['warehouse'] ?? '—' }}</td>
                                    <td class="py-2 pr-3">{{ $movement['label'] }}</td>
                                    <td class="whitespace-nowrap py-2 text-right font-semibold" style="color: {{ $movement['qty'] >= 0 ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $movement['qty'] >= 0 ? '+' : '−' }}{{ $qty(abs($movement['qty'])) }} {{ $item->unit }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-5 text-center" style="color: var(--text-muted);">No stock movements recorded for this item.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border p-5 xl:col-span-2" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-lg font-bold" style="color: var(--text-primary);">Purchase history <span class="text-sm font-normal" style="color: var(--text-muted);">({{ $purchaseLines->count() }} lines)</span></h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[650px] text-sm">
                        <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="py-2 pr-3">PO</th><th class="py-2 pr-3">Supplier</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3 text-right">Qty</th>@if ($canSeePrices)<th class="py-2 text-right">Unit rate</th>@endif</tr></thead>
                        <tbody>
                            @forelse ($purchaseLines as $line)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-2 pr-3">{{ $line->purchaseOrder?->po_number ?: 'PO #'.$line->purchase_order_id }}</td>
                                    <td class="py-2 pr-3">{{ $line->purchaseOrder?->vendor_name ?: '—' }}</td>
                                    <td class="py-2 pr-3">{{ $line->purchaseOrder ? ($receiptStatusByPo[$line->purchaseOrder->id] ?? $line->purchaseOrder->status ?? '—') : '—' }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $qty($line->quantity) }} {{ $line->uom ?: $item->unit }}</td>
                                    @if ($canSeePrices)<td class="py-2 text-right">{{ number_format((float) $line->list_price, 2) }}</td>@endif
                                </tr>
                            @empty
                                <tr><td colspan="{{ $canSeePrices ? 5 : 4 }}" class="py-5 text-center" style="color: var(--text-muted);">No purchase lines linked to this item.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($recipeLines->isNotEmpty() || $usedIn->isNotEmpty())
                <section class="rounded-2xl border p-5 xl:col-span-2" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="text-lg font-bold" style="color: var(--text-primary);">Recipe / composite usage</h2>
                    @if ($recipeLines->isNotEmpty())
                        <h3 class="mt-3 text-sm font-semibold" style="color: var(--text-secondary);">Components in this finished good</h3>
                        <div class="mt-2 space-y-2">
                            @foreach ($recipeLines as $line)
                                <div class="flex flex-wrap justify-between gap-2 border-t py-2 text-sm" style="border-color: var(--border);"><span>{{ $line->component?->name ?: ($canSeeSku ? $line->component?->sku : ($line->component?->code ?: 'Component item')) }}</span><span>{{ $qty($line->qty_per_unit) }} {{ $line->component?->unit }} per {{ $item->unit }}</span></div>
                            @endforeach
                        </div>
                    @endif
                    @if ($usedIn->isNotEmpty())
                        <h3 class="mt-4 text-sm font-semibold" style="color: var(--text-secondary);">Used to make</h3>
                        <div class="mt-2 space-y-2">
                            @foreach ($usedIn as $product)
                                <div class="flex flex-wrap justify-between gap-2 border-t py-2 text-sm" style="border-color: var(--border);"><span>{{ $product->name ?: ($canSeeSku ? $product->sku : 'Finished good') }}</span><span>{{ $qty($product->qty_per_unit) }} {{ $item->unit }} per finished unit</span></div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            <section class="rounded-2xl border p-5 xl:col-span-2" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-lg font-bold" style="color: var(--text-primary);">Item audit history <span class="text-sm font-normal" style="color: var(--text-muted);">({{ $audit->count() }} entries)</span></h2>
                <div class="mt-3 space-y-2">
                    @forelse ($audit as $entry)
                        <div class="flex flex-wrap justify-between gap-2 border-t py-2 text-sm" style="border-color: var(--border);">
                            <div><div class="font-semibold" style="color: var(--text-primary);">{{ $entry->action }}</div>@if ($entry->detail)<div style="color: var(--text-secondary);">{{ $entry->detail }}</div>@endif</div>
                            <time class="text-xs" style="color: var(--text-muted);">{{ $entry->created_at?->format('d M Y, H:i') }}</time>
                        </div>
                    @empty
                        <p class="py-3 text-sm" style="color: var(--text-muted);">No item-specific audit events recorded.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
