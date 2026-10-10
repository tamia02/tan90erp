<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\LedgerEntry;
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

    public string $type = '';
    public ?int $historyItemId = null;
    public string $historyKind = ''; // '' = everything, or received / produced / consumed / wastage / dispatched / adjusted

    public function showHistory(int $itemId, string $kind = ''): void
    {
        $this->historyItemId = $itemId;
        $this->historyKind = $kind;
    }
    public bool $showArchived = false;
    public string $search = '';
    public int $perPage = 100;

    public function updatedSearch(): void
    {
        $this->perPage = 100;
    }

    public function with(): array
    {
        $all = InventoryItem::when($this->type, fn ($q) => $q->where('item_type', $this->type))
            ->when(! $this->showArchived, fn ($q) => $q->where('active', true))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('code', 'like', '%'.trim($this->search).'%')
                ->when(InventoryItem::canSeeSkuName(auth()->user()), fn ($w) => $w->orWhere('sku', 'like', '%'.trim($this->search).'%'), fn ($w) => $w->orWhere(fn ($n) => $n->whereNull('name')->where('sku', 'like', '%'.trim($this->search).'%')))))
            ->orderByRaw("CASE item_type WHEN 'rm' THEN 1 WHEN 'pm' THEN 2 WHEN 'fg' THEN 3 ELSE 4 END")->orderBy('sku')->get();

        // 2,000+ Zoho items: items with any stock or movement first, 100 at a time.
        $allBalances = app(InventoryService::class)->balances($all);
        $active = fn ($item) => collect($allBalances[$item->id])->except('final')->contains(fn ($v) => abs($v) > 0.0005);
        $total = $all->count();
        $items = $all->sortBy(fn ($item) => $active($item) ? 0 : 1)->values()->take($this->perPage);

        $historyItem = $this->historyItemId ? InventoryItem::find($this->historyItemId) : null;
        $history = $historyItem ? app(InventoryService::class)->history($historyItem) : collect();

        return [
            'items' => $items,
            'total' => $total,
            'balances' => $allBalances,
            'types' => InventoryItem::TYPES,
            'historyItem' => $historyItem,
            'history' => $this->historyKind === '' ? $history : $history->where('kind', $this->historyKind)->values(),
            'kinds' => ['' => 'Everything', 'received' => 'Received (GRN)', 'produced' => 'Produced', 'consumed' => 'Converted', 'wastage' => 'Wastage', 'dispatched' => 'Dispatched', 'adjusted' => 'Adjusted'],
            'historyBalance' => $historyItem ? $allBalances[$historyItem->id] ?? app(InventoryService::class)->balances(collect([$historyItem]))[$historyItem->id] : null,
        ];
    }
}; ?>

<div>
    @php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0'; @endphp
    @include('partials.inventory-tabs', ['current' => 'inventory.stock', 'title' => 'Inventory Summary', 'subtitle' => 'Final = Current + Received + Produced ± Adjusted − Converted − Wastage − Dispatched. Click any number to see exactly when and how it happened.'])

    <div class="flex flex-wrap items-center gap-2 mb-4">
        <select wire:model.live="type" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            <option value="">All item types</option>
            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);"><input type="checkbox" wire:model.live="showArchived"> Show archived items</label>
        <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search items…" class="rounded-lg border px-3 py-2 text-sm flex-1 min-w-[200px]" style="border-color: var(--border);">
        <span class="text-xs" style="color: var(--text-muted);">Showing {{ $items->count() }} of {{ $total }} (items with stock or movements first)</span>
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
                    <th class="px-4 py-3 text-right">± Adjusted</th>
                    <th class="px-4 py-3 text-right">= Final</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php $b = $balances[$item->id]; @endphp
                    @php $cell = fn ($kind, $value, $color) => '<button type="button" wire:click="showHistory('.$item->id.', \''.$kind.'\')" class="hover:underline" style="color: '.$color.';" title="See each one">'.$fmt($value).'</button>'; @endphp
                    <tr wire:key="stock-{{ $item->id }}" style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);"><button type="button" wire:click="showHistory({{ $item->id }})" class="text-left hover:underline" title="See every movement">{{ $item->displayName() }}</button> <span class="text-xs font-normal" style="color: var(--text-muted);">{{ $item->unit }}</span></td>
                        <td class="px-4 py-3 text-xs">{{ $item->typeLabel() }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($b['opening']) }}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('received', $b['received'], 'var(--status-good)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('produced', $b['produced'], 'var(--status-good)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('consumed', $b['consumed'], 'var(--status-warning)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('wastage', $b['wastage'], 'var(--status-critical)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('dispatched', $b['dispatched'], 'var(--status-warning)') !!}</td>
                        <td class="px-4 py-3 text-right">{!! $cell('adjusted', $b['adjusted'], 'var(--brand)') !!}</td>
                        <td class="px-4 py-3 text-right font-bold">{!! $cell('', $b['final'], 'var(--text-primary)') !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center" style="color: var(--text-muted);">No items yet - start at <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($items->count() < $total)
        <button type="button" wire:click="$set('perPage', {{ $perPage + 100 }})" class="mt-3 rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Show more ({{ $total - $items->count() }} more)</button>
    @endif

    @if ($historyItem)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto" style="background: rgba(15, 23, 42, .45);" role="dialog" aria-modal="true" wire:key="stock-history-popup" wire:click.self="$set('historyItemId', null)" x-data x-on:keydown.escape.window="$wire.set('historyItemId', null)">
            <section class="rounded-2xl border p-5 w-full max-w-3xl mt-16 shadow-2xl" style="background: var(--surface-3); border-color: var(--brand);">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary);">History: {{ $historyItem->displayName() }}</h2>
                    <button type="button" wire:click="$set('historyItemId', null)" class="text-xs" style="color: var(--text-muted);">Close</button>
                </div>
                <p class="text-xs mb-3" style="color: var(--text-secondary);">In stock now: <b>{{ $fmt($historyBalance['final']) }} {{ $historyItem->unit }}</b>{{ (float) $historyItem->opening_qty ? ' · earlier stock '.$fmt($historyItem->opening_qty) : '' }}</p>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach ($kinds as $kind => $label)
                        <button type="button" wire:click="$set('historyKind', '{{ $kind }}')" class="rounded-full border px-2.5 py-1 text-xs font-semibold" style="{{ $historyKind === $kind ? 'background: var(--brand); color: #fff; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-secondary);' }}">{{ $label }}</button>
                    @endforeach
                </div>
                @forelse ($history as $row)
                    <div class="flex justify-between gap-3 border-t py-2 text-sm" style="border-color: var(--border);">
                        <span>{{ $row['label'] }} <span class="text-xs" style="color: var(--text-muted);">{{ $row['at']?->format('d M Y, H:i') }}</span></span>
                        <span class="font-semibold whitespace-nowrap" style="color: {{ $row['qty'] >= 0 ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $row['qty'] >= 0 ? '+' : '' }}{{ $fmt($row['qty']) }} {{ $historyItem->unit }}</span>
                    </div>
                @empty
                    <p class="text-sm" style="color: var(--text-muted);">Nothing of this kind yet.</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
