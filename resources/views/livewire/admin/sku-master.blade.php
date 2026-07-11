<?php

use App\Models\SkuMaster;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $adding = false;
    public ?int $expanded = null;

    public string $sku = '';
    public string $category = 'PCM — Raw Material';
    public string $unit = 'KG';
    public string $defaultBin = '';
    public bool $mapped = true;
    public string $productCode = '';
    public string $vendorName = '';
    public string $unitPrice = '';
    public string $quantityInStock = '';
    public string $reorderLevel = '';
    public string $description = '';

    public array $categories = ['PCM — Raw Material', 'PCM — Trial', 'Packaging & Structural', 'Safety & PPE', 'Equipment & Rental', 'Consumable', 'Other'];
    public array $units = ['KG', 'LTR', 'MT', 'NOS', 'BOX'];

    public function addSku(): void
    {
        $this->validate(['sku' => ['required', 'string', 'max:255']]);

        $entry = SkuMaster::create([
            'sku' => $this->sku,
            'category' => $this->category,
            'unit' => $this->unit,
            'default_bin' => $this->defaultBin ?: null,
            'mapped' => $this->mapped,
            'product_code' => $this->productCode ?: null,
            'vendor_name' => $this->vendorName ?: null,
            'unit_price' => $this->unitPrice !== '' ? $this->unitPrice : null,
            'quantity_in_stock' => $this->quantityInStock !== '' ? $this->quantityInStock : null,
            'reorder_level' => $this->reorderLevel !== '' ? $this->reorderLevel : null,
            'description' => $this->description ?: null,
        ]);

        AuditLogger::log('SKU added to master', "{$entry->sku} · {$entry->category}", $entry);

        $this->reset(['sku', 'defaultBin', 'productCode', 'vendorName', 'unitPrice', 'quantityInStock', 'reorderLevel', 'description', 'adding']);
        $this->category = 'PCM — Raw Material';
        $this->unit = 'KG';
        $this->mapped = true;
    }

    public function deleteSku(int $id): void
    {
        $entry = SkuMaster::findOrFail($id);
        AuditLogger::log('SKU removed from master', $entry->sku);
        $entry->delete();
    }

    public function with(): array
    {
        return ['skus' => SkuMaster::orderByDesc('created_at')->get()];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">SKU Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every product, matching the client's Zoho CRM Products module — Guard Bill Scan checks mapped status against this list.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            {{ $adding ? 'Cancel' : 'Add SKU' }}
        </button>
    </div>


    @if ($adding)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Product Name</span>
                <input wire:model="sku" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                @error('sku') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Product Code</span>
                <input wire:model="productCode" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Category</span>
                <select wire:model="category" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($categories as $c) <option value="{{ $c }}">{{ $c }}</option> @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Usage Unit</span>
                <select wire:model="unit" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($units as $u) <option value="{{ $u }}">{{ $u }}</option> @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor Name</span>
                <input wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Unit Price (₹)</span>
                <input wire:model="unitPrice" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Quantity in Stock</span>
                <input wire:model="quantityInStock" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Reorder Level</span>
                <input wire:model="reorderLevel" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Default bin (Tan90-only)</span>
                <input wire:model="defaultBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-A1" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-3">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <textarea wire:model="description" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-3">
                <input wire:model="mapped" type="checkbox" class="w-4 h-4" />
                <span style="color: var(--text-primary);">Mapped (Tan90-only) — passes the "SKU not mapped" gate check</span>
            </label>
            <button wire:click="addSku" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">Product Name</th>
                        <th class="px-4 py-2.5 font-medium">Product Code</th>
                        <th class="px-4 py-2.5 font-medium">Mapped</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($skus as $s)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', {{ $expanded === $s->id ? 'null' : $s->id }})" style="color: var(--text-muted);">{{ $expanded === $s->id ? '▾' : '▸' }}</button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $s->sku }}</td>
                            <td class="px-4 py-2.5 text-xs font-mono" style="color: var(--text-secondary);">{{ $s->product_code ?? '—' }}</td>
                            <td class="px-4 py-2.5">{{ $s->mapped ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deleteSku({{ $s->id }})" wire:confirm="Remove {{ $s->sku }}?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        @if ($expanded === $s->id)
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="4" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Category</div><div style="color: var(--text-primary);">{{ $s->category }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Vendor</div><div style="color: var(--text-primary);">{{ $s->vendor_name ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Unit Price</div><div style="color: var(--text-primary);">{{ $s->unit_price ? '₹'.$s->unit_price : '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Qty in Stock</div><div style="color: var(--text-primary);">{{ $s->quantity_in_stock ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Reorder Level</div><div style="color: var(--text-primary);">{{ $s->reorder_level ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Default Bin</div><div style="color: var(--text-primary);">{{ $s->default_bin ?? '—' }}</div></div>
                                    </div>
                                    @if ($s->description)
                                        <div class="text-xs mt-2"><span class="font-semibold" style="color: var(--text-muted);">Description: </span><span style="color: var(--text-primary);">{{ $s->description }}</span></div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No SKUs mapped yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
