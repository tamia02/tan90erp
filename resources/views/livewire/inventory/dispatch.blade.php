<?php

use App\Models\Inventory\InventoryDispatch;
use App\Models\Inventory\InventoryItem;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
    }

    public string $itemId = '';
    public string $qty = '';
    public string $customerName = '';
    public string $reference = '';
    public string $notes = '';

    public function dispatchGoods(InventoryService $inventory): void
    {
        $this->validate([
            'itemId' => ['required', 'exists:inventory_items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'customerName' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
        ], ['itemId.required' => 'Pick what you are dispatching.', 'customerName.required' => 'Enter the customer name.', 'qty.gt' => 'Quantity must be more than 0.']);

        $dispatch = $inventory->dispatch(InventoryItem::findOrFail($this->itemId), (float) $this->qty, trim($this->customerName), $this->reference ?: null, $this->notes ?: null, auth()->user());
        $item = $dispatch->item;
        AuditLogger::log('Goods dispatched', "{$dispatch->number} · {$dispatch->qty} {$item->unit} {$item->displayName()} → {$dispatch->customer_name}", $dispatch);

        session()->flash('success', "{$dispatch->number}: dispatched {$this->qty} {$item->unit} of {$item->displayName()} to {$dispatch->customer_name}.");
        $this->reset(['itemId', 'qty', 'customerName', 'reference', 'notes']);
    }

    public function with(): array
    {
        $items = InventoryItem::whereIn('item_type', InventoryItem::SELLABLE_TYPES)->where('active', true)->orderBy('item_type')->orderBy('sku')->get();

        return [
            'items' => $items,
            'balances' => app(InventoryService::class)->balances($items),
            'recent' => InventoryDispatch::with(['item', 'creator'])->latest('id')->limit(15)->get(),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.dispatch', 'title' => 'Dispatch', 'subtitle' => 'Send Finished Goods or Trading Goods to a customer. Stock goes down straight away; you cannot dispatch more than you have.'])

    <form wire:submit="dispatchGoods" class="rounded-2xl border p-5 space-y-3" style="background: var(--surface-3); border-color: var(--border);">
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Item (Finished Good or Trading Good)</span>
                <select wire:model.live="itemId" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                    <option value="">Select…</option>
                    @foreach ($items as $item)<option value="{{ $item->id }}">{{ $item->displayName() }} - {{ $item->item_type === 'fg' ? 'FG' : 'Trading' }} - {{ $balances[$item->id]['final'] }} {{ $item->unit }} in stock</option>@endforeach
                </select>
                @error('itemId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Quantity</span>
                <input wire:model="qty" type="number" step="0.001" min="0" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                @error('qty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Customer</span>
                <input wire:model="customerName" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                @error('customerName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Invoice / Sales order no. (optional)</span>
                <input wire:model="reference" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
            </label>
        </div>
        <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Notes (optional)</span>
            <input wire:model="notes" class="rounded-lg border px-3 py-2" style="border-color: var(--border);" placeholder="Vehicle / transporter / remarks">
        </label>
        @if ($items->isEmpty())
            <p class="text-sm" style="color: var(--status-warning);">No Finished Goods or Trading Goods yet - add them in <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items &amp; Recipes</a>.</p>
        @endif
        <button type="submit" wire:loading.attr="disabled" wire:target="dispatchGoods" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Dispatch &amp; update stock</button>
    </form>

    <section class="rounded-2xl border p-5 mt-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Recent dispatches</h2>
        @forelse ($recent as $dispatch)
            <div wire:key="dsp-{{ $dispatch->id }}" class="rounded-lg border px-3 py-2 mb-2 text-sm" style="border-color: var(--border);">
                <span class="font-semibold">{{ $dispatch->number }}</span> · -{{ rtrim(rtrim((string) $dispatch->qty, '0'), '.') }} {{ $dispatch->item?->unit }} {{ $dispatch->item?->displayName() }} → {{ $dispatch->customer_name }}
                @if ($dispatch->reference) · Ref {{ $dispatch->reference }} @endif
                <span class="text-xs" style="color: var(--text-muted);">· {{ $dispatch->created_at->format('d M Y, H:i') }} · {{ $dispatch->creator?->name }}</span>
            </div>
        @empty
            <p class="text-sm" style="color: var(--text-muted);">Nothing dispatched yet.</p>
        @endforelse
    </section>
</div>
