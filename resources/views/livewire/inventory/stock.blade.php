<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\LedgerEntry;
use App\Services\Inventory\InventoryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $type = '';
    public ?int $historyItemId = null;
    public bool $showArchived = false;

    public function with(): array
    {
        $items = InventoryItem::when($this->type, fn ($q) => $q->where('item_type', $this->type))
            ->when(! $this->showArchived, fn ($q) => $q->where('active', true))
            ->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')->get();

        $historyItem = $this->historyItemId ? InventoryItem::find($this->historyItemId) : null;
        $history = collect();
        if ($historyItem) {
            $grn = LedgerEntry::with('gateEntry')->where('sku', $historyItem->sku)->where('bucket', 'available')->get()
                ->map(fn ($entry) => ['at' => $entry->created_at, 'label' => 'Received (GRN) · '.($entry->gateEntry?->gate_no ?? '').' '.($entry->gateEntry?->po_number ?? ''), 'qty' => (float) $entry->qty]);
            $moves = InventoryMovement::with(['production', 'dispatch'])->where('item_id', $historyItem->id)->get()
                ->map(fn ($m) => [
                    'at' => $m->created_at,
                    'label' => match ($m->type) {
                        'produced' => 'Produced · '.$m->production?->number,
                        'consumed' => 'Converted (used in production) · '.$m->production?->number,
                        'wastage' => 'Wastage · '.$m->production?->number,
                        'dispatched' => 'Dispatched · '.$m->dispatch?->number.' → '.$m->dispatch?->customer_name,
                        default => ucfirst($m->type),
                    },
                    'qty' => in_array($m->type, InventoryMovement::IN_TYPES, true) ? (float) $m->qty : -(float) $m->qty,
                ]);
            $history = $grn->concat($moves)->sortByDesc('at')->values();
        }

        return [
            'items' => $items,
            'balances' => app(InventoryService::class)->balances($items),
            'types' => InventoryItem::TYPES,
            'historyItem' => $historyItem,
            'history' => $history,
        ];
    }
}; ?>

<div>
    @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0'; @endphp
    @include('partials.inventory-tabs', ['current' => 'inventory.stock', 'title' => 'Stock Summary', 'subtitle' => 'Final Inventory = Current + Received − (Converted + Wastage) − Dispatched. Finished Goods also add what was Produced.'])

    <div class="flex flex-wrap items-center gap-2 mb-4">
        <select wire:model.live="type" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            <option value="">All item types</option>
            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><input type="checkbox" wire:model.live="showArchived"> Show archived items</label>
    </div>

    <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm min-w-[900px]">
            <thead>
                <tr class="text-left text-xs uppercase" style="color: var(--text-muted);">
                    <th class="px-4 py-3">Item</th><th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3 text-right">Current<br><span class="normal-case font-normal">(opening)</span></th>
                    <th class="px-4 py-3 text-right">+ Received<br><span class="normal-case font-normal">(GRN)</span></th>
                    <th class="px-4 py-3 text-right">+ Produced</th>
                    <th class="px-4 py-3 text-right">− Converted</th>
                    <th class="px-4 py-3 text-right">− Wastage</th>
                    <th class="px-4 py-3 text-right">− Dispatched</th>
                    <th class="px-4 py-3 text-right">= Final</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php $b = $balances[$item->id]; @endphp
                    <tr wire:key="stock-{{ $item->id }}" wire:click="$set('historyItemId', {{ $item->id }})" class="cursor-pointer hover:opacity-80" style="border-top: 1px solid var(--border);" title="Click to see every movement">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $item->displayName() }} <span class="text-xs font-normal" style="color: var(--text-muted);">{{ $item->unit }}</span></td>
                        <td class="px-4 py-3 text-xs">{{ $item->typeLabel() }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($b['opening']) }}</td>
                        <td class="px-4 py-3 text-right" style="color: var(--status-good);">{{ $fmt($b['received']) }}</td>
                        <td class="px-4 py-3 text-right" style="color: var(--status-good);">{{ $fmt($b['produced']) }}</td>
                        <td class="px-4 py-3 text-right" style="color: var(--status-warning);">{{ $fmt($b['consumed']) }}</td>
                        <td class="px-4 py-3 text-right" style="color: var(--status-critical);">{{ $fmt($b['wastage']) }}</td>
                        <td class="px-4 py-3 text-right" style="color: var(--status-warning);">{{ $fmt($b['dispatched']) }}</td>
                        <td class="px-4 py-3 text-right font-bold" style="color: var(--text-primary);">{{ $fmt($b['final']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet - start at <a href="{{ route('inventory.items') }}" wire:navigate class="underline">1. Items &amp; Recipes</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($historyItem)
        <section class="rounded-2xl border p-5 mt-6" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">History: {{ $historyItem->displayName() }}</h2>
                <button type="button" wire:click="$set('historyItemId', null)" class="text-xs" style="color: var(--text-muted);">Close</button>
            </div>
            <div class="text-sm mb-2" style="color: var(--text-secondary);">Current stock at start: {{ $fmt($historyItem->opening_qty) }} {{ $historyItem->unit }}</div>
            @forelse ($history as $row)
                <div class="flex justify-between border-t py-2 text-sm" style="border-color: var(--border);">
                    <span>{{ $row['label'] }} <span class="text-xs" style="color: var(--text-muted);">{{ $row['at']?->format('d M Y, H:i') }}</span></span>
                    <span class="font-semibold" style="color: {{ $row['qty'] >= 0 ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $row['qty'] >= 0 ? '+' : '' }}{{ $fmt($row['qty']) }} {{ $historyItem->unit }}</span>
                </div>
            @empty
                <p class="text-sm" style="color: var(--text-muted);">No movements yet.</p>
            @endforelse
        </section>
    @endif
</div>
