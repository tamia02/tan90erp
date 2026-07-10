<?php

use App\Models\PurchaseOrder;
use App\Models\VendorMaster;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $adding = false;
    public ?int $expanded = null;

    public string $poNumber = '';
    public string $subject = '';
    public string $vendorName = '';
    public string $status = 'Created';
    public string $poDate = '';
    public string $dueDate = '';
    public string $product = '';
    public string $quantity = '';
    public string $listPrice = '';

    public array $statuses = ['Created', 'Approved', 'Delivered', 'Cancelled'];

    public function addPo(): void
    {
        $this->validate([
            'poNumber' => ['required', 'string', 'max:255'],
            'vendorName' => ['required', 'string'],
            'product' => ['required', 'string'],
            'quantity' => ['required', 'numeric'],
            'listPrice' => ['required', 'numeric'],
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $this->poNumber,
            'subject' => $this->subject ?: null,
            'vendor_name' => $this->vendorName,
            'status' => $this->status,
            'po_date' => $this->poDate ?: null,
            'due_date' => $this->dueDate ?: null,
        ]);

        $po->lines()->create([
            'product' => $this->product,
            'quantity' => $this->quantity,
            'list_price' => $this->listPrice,
        ]);

        AuditLogger::log('Purchase Order added to master', "{$po->po_number} · {$po->vendor_name}");

        $this->reset(['poNumber', 'subject', 'vendorName', 'poDate', 'dueDate', 'product', 'quantity', 'listPrice', 'adding']);
        $this->status = 'Created';
    }

    public function deletePo(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        AuditLogger::log('Purchase Order removed from master', $po->po_number);
        $po->delete();
    }

    public function with(): array
    {
        return [
            'orders' => PurchaseOrder::with('lines')->orderByDesc('created_at')->get(),
            'vendors' => VendorMaster::orderBy('vendor_name')->get(),
        ];
    }
}; ?>

<div class="max-w-6xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">PO Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every purchase order, matching the client's Zoho CRM Purchase Orders module — Guard Bill Scan validates rate, quantity and vendor GST against this.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            {{ $adding ? 'Cancel' : 'Add PO' }}
        </button>
    </div>


    @if ($adding)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Number</span>
                <input wire:model="poNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PO RM 2627 0099" />
                @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Subject</span>
                <input wire:model="subject" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor Name</span>
                <select wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">Choose vendor…</option>
                    @foreach ($vendors as $v) <option value="{{ $v->vendor_name }}">{{ $v->vendor_name }}</option> @endforeach
                </select>
                @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Date</span>
                <input wire:model="poDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Due Date</span>
                <input wire:model="dueDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Status</span>
                <select wire:model="status" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($statuses as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
                </select>
            </label>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border);">
                <div class="text-xs font-semibold mb-2" style="color: var(--text-muted);">PURCHASE ITEM</div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Product Name</span>
                        <input wire:model="product" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                        @error('product') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                        <input wire:model="quantity" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        @error('quantity') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">List Price (₹)</span>
                        <input wire:model="listPrice" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        @error('listPrice') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                </div>
            </div>
            <button wire:click="addPo" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">PO Number</th>
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 font-medium">Grand Total</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $po)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', {{ $expanded === $po->id ? 'null' : $po->id }})" style="color: var(--text-muted);">{{ $expanded === $po->id ? '▾' : '▸' }}</button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $po->po_number }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $po->vendor_name }}</td>
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: var(--text-secondary);">{{ $po->status }}</td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">₹{{ number_format($po->grandTotal(), 2) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deletePo({{ $po->id }})" wire:confirm="Remove PO {{ $po->po_number }}?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        @if ($expanded === $po->id)
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="5" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mb-2">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Subject</div><div style="color: var(--text-primary);">{{ $po->subject ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">PO Date</div><div style="color: var(--text-primary);">{{ $po->po_date?->format('Y-m-d') ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Due Date</div><div style="color: var(--text-primary);">{{ $po->due_date?->format('Y-m-d') ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Requisition #</div><div style="color: var(--text-primary);">{{ $po->requisition_number ?? '—' }}</div></div>
                                    </div>
                                    <div class="overflow-x-auto rounded-lg border" style="border-color: var(--border);">
                                        <table class="w-full text-xs">
                                            <thead><tr style="color: var(--text-muted); background: var(--surface-3);"><th class="px-3 py-1.5 text-left">Product</th><th class="px-3 py-1.5 text-left">Qty</th><th class="px-3 py-1.5 text-left">List Price</th></tr></thead>
                                            <tbody>
                                                @foreach ($po->lines as $line)
                                                    <tr style="border-top: 1px solid var(--border);">
                                                        <td class="px-3 py-1.5" style="color: var(--text-primary);">{{ $line->product }}</td>
                                                        <td class="px-3 py-1.5">{{ $line->quantity }}</td>
                                                        <td class="px-3 py-1.5">₹{{ $line->list_price }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
