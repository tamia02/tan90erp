<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryProduction;
use App\Services\AuditLogger;
use App\Models\SkuMaster;
use App\Models\ZohoLocation;
use App\Services\Inventory\InventoryService;
use App\Services\ZohoInventoryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
    }

    public string $fgItemId = '';
    public string $qty = '';
    public array $wastage = [];
    public string $notes = '';
    public string $zohoLocationId = '';

    public function updatedFgItemId(): void
    {
        $this->wastage = [];
        $this->resetValidation();
    }

    // Changing qty/wastage invalidates the last "Not enough ..." message.
    public function updated(string $property): void
    {
        if ($property === 'qty' || str_starts_with($property, 'wastage')) {
            $this->resetValidation();
        }
    }

    public function produce(InventoryService $inventory): void
    {
        $this->validate([
            'fgItemId' => ['required', 'exists:inventory_items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'wastage.*' => ['nullable', 'numeric', 'min:0'],
            'zohoLocationId' => [$this->sendsToZoho() ? 'required' : 'nullable', 'string'],
        ], ['fgItemId.required' => 'Pick the Finished Good to make.', 'qty.gt' => 'Quantity must be more than 0.', 'zohoLocationId.required' => 'Choose the Zoho warehouse this bundle is made in.']);

        $sendsToZoho = $this->sendsToZoho();
        $production = $inventory->produce(InventoryItem::findOrFail($this->fgItemId), (float) $this->qty, $this->wastage, $this->notes ?: null, auth()->user());
        $fg = $production->fgItem;
        AuditLogger::log('Production posted', "{$production->number} · {$production->qty_produced} {$fg->unit} {$fg->displayName()}", $production);

        $zohoNote = '';
        if ($sendsToZoho) {
            $production->forceFill(['zoho_location_id' => $this->zohoLocationId])->save();
            $status = app(ZohoInventoryService::class)->pushBundle($production);
            $zohoNote = $status === 'sent' ? ' Bundle created in Zoho.' : ' Not sent to Zoho: '.$production->fresh()->zoho_error.' (use Retry below).';
        }

        session()->flash('success', "{$production->number}: made {$this->qty} {$fg->unit} of {$fg->displayName()}. Raw/packaging material and wastage have been deducted.".$zohoNote);
        $this->reset(['fgItemId', 'qty', 'wastage', 'notes']);
    }

    public function retryZoho(int $productionId): void
    {
        $production = InventoryProduction::findOrFail($productionId);
        $status = app(ZohoInventoryService::class)->pushBundle($production);
        session()->flash('success', $status === 'sent' ? "{$production->number} sent to Zoho." : "{$production->number} still not sent to Zoho: ".$production->fresh()->zoho_error);
    }

    // Zoho connected for writing and the chosen Finished Good is a Zoho item.
    private function sendsToZoho(): bool
    {
        $zoho = app(ZohoInventoryService::class);
        if (! $this->fgItemId || ! $zoho->isActive() || $zoho->readOnly() || ! config('services.zoho.inventory.write_enabled', true)) {
            return false;
        }
        $fg = InventoryItem::find($this->fgItemId);

        return $fg && SkuMaster::whereRaw('LOWER(sku) = ?', [mb_strtolower($fg->sku)])->whereNotNull('zoho_item_id')->exists();
    }

    public function with(): array
    {
        $inventory = app(InventoryService::class);
        $fgItems = InventoryItem::where('item_type', 'fg')->where('active', true)->with('bomLines.component')->orderBy('sku')->get();
        $selected = $this->fgItemId ? $fgItems->firstWhere('id', (int) $this->fgItemId) : null;
        $components = $selected ? $selected->bomLines->pluck('component')->filter() : collect();

        return [
            'fgItems' => $fgItems,
            'selected' => $selected,
            'componentBalances' => $inventory->balances($components),
            'recent' => InventoryProduction::with(['fgItem', 'movements.item', 'creator'])->latest('id')->limit(10)->get(),
            'sendsToZoho' => $this->sendsToZoho(),
            'zohoLocations' => ZohoLocation::where('active', true)->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.production', 'title' => 'Create Bundle (Production)', 'subtitle' => 'Turn Raw Material + Packaging Material into a Finished Good. The recipe decides how much RM/PM is used; add any wastage per material.'])

    {{-- Inputs use deferred wire:model so a fast click on "Create Bundle" always sends the typed qty/wastage; the debounced $refresh only redraws the preview. (wire:model.live.debounce lost values typed <400ms before the click.) --}}
    <form wire:submit="produce" class="rounded-2xl border p-5 space-y-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="grid sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1 text-sm sm:col-span-2"><span class="font-medium">Finished Good to make</span>
                <select wire:model.live="fgItemId" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                    <option value="">Select…</option>
                    @foreach ($fgItems as $fg)<option value="{{ $fg->id }}">{{ $fg->displayName() }}{{ $fg->bomLines->isEmpty() ? ' (no recipe yet)' : '' }}</option>@endforeach
                </select>
                @error('fgItemId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Quantity to make{{ $selected ? " ({$selected->unit})" : '' }}</span>
                <input wire:model="qty" x-on:input.debounce.400ms="$wire.$refresh()" type="number" step="0.001" min="0" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                @error('qty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
        </div>

        @if ($fgItems->isEmpty())
            <p class="text-sm" style="color: var(--status-warning);">No Finished Goods yet - add one in <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items &amp; Recipes</a>.</p>
        @endif

        @if ($selected)
            @if ($selected->bomLines->isEmpty())
                <p class="text-sm" style="color: var(--status-warning);">{{ $selected->displayName() }} has no recipe. Add one in <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items &amp; Recipes</a>.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[640px]">
                        <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="py-2 pr-3">Material</th><th class="py-2 pr-3 text-right">Per 1 {{ $selected->unit }}</th><th class="py-2 pr-3 text-right">Will be used</th><th class="py-2 pr-3">Wastage</th><th class="py-2 pr-3 text-right">In stock</th><th class="py-2 text-right">Left after</th></tr></thead>
                        <tbody>
                            @foreach ($selected->bomLines as $line)
                                @php
                                    $component = $line->component;
                                    $use = round((float) $line->qty_per_unit * (float) ($qty ?: 0), 3);
                                    $waste = (float) ($wastage[$line->component_item_id] ?? 0);
                                    $have = $componentBalances[$line->component_item_id]['final'] ?? 0;
                                    $left = round($have - $use - $waste, 3);
                                @endphp
                                <tr wire:key="prod-line-{{ $line->id }}" style="border-top: 1px solid var(--border);">
                                    <td class="py-2 pr-3"><span class="font-medium">{{ $component?->displayName() }}</span> <span class="text-xs" style="color: var(--text-muted);">{{ $component?->typeLabel() }}</span></td>
                                    <td class="py-2 pr-3 text-right">{{ rtrim(rtrim((string) $line->qty_per_unit, '0'), '.') }} {{ $component?->unit }}</td>
                                    <td class="py-2 pr-3 text-right font-semibold">{{ $use }} {{ $component?->unit }}</td>
                                    <td class="py-2 pr-3"><input wire:model="wastage.{{ $line->component_item_id }}" x-on:input.debounce.400ms="$wire.$refresh()" type="number" step="0.001" min="0" placeholder="0" class="rounded-lg border px-2 py-1 w-28" style="border-color: var(--border);">
                                        @error('wastage.'.$line->component_item_id) <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror</td>
                                    <td class="py-2 pr-3 text-right">{{ $have }} {{ $component?->unit }}</td>
                                    <td class="py-2 text-right font-semibold" style="color: {{ $left < 0 ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $left < 0 ? 'Short by '.abs($left) : $left }} {{ $component?->unit }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($sendsToZoho)
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Zoho warehouse *</span>
                        <select wire:model="zohoLocationId" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                            <option value="">Select the warehouse this is made in…</option>
                            @foreach ($zohoLocations as $location)<option value="{{ $location->zoho_location_id }}">{{ $location->name }}</option>@endforeach
                        </select>
                        <span class="text-xs" style="color: var(--text-muted);">This bundle will also be created in Zoho Inventory, so Zoho's stock moves the same way.</span>
                        @error('zohoLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                @endif
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Notes (optional)</span>
                    <input wire:model="notes" class="rounded-lg border px-3 py-2" style="border-color: var(--border);" placeholder="Batch / shift / remarks">
                </label>
                <button type="submit" wire:loading.attr="disabled" wire:target="produce" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Create Bundle &amp; update stock</button>
            @endif
        @endif
    </form>

    <section class="rounded-2xl border p-5 mt-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Recent production</h2>
        @forelse ($recent as $production)
            <div wire:key="prod-{{ $production->id }}" class="rounded-lg border px-3 py-2 mb-2 text-sm" style="border-color: var(--border);">
                <div class="font-semibold">{{ $production->number }} · +{{ rtrim(rtrim((string) $production->qty_produced, '0'), '.') }} {{ $production->fgItem?->unit }} {{ $production->fgItem?->displayName() }} <span class="font-normal text-xs" style="color: var(--text-muted);">{{ $production->created_at->format('d M Y, H:i') }} · {{ $production->creator?->name }}</span></div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    @foreach ($production->movements->whereIn('type', ['consumed', 'wastage']) as $movement)
                        {{ $movement->type === 'wastage' ? 'Wastage' : 'Used' }} {{ rtrim(rtrim((string) $movement->qty, '0'), '.') }} {{ $movement->item?->unit }} {{ $movement->item?->displayName() }}@if (! $loop->last) · @endif
                    @endforeach
                    @if ($production->notes) · Note: {{ $production->notes }} @endif
                    @if ($production->zoho_status === 'sent')
                        <span class="ml-1 font-semibold" style="color: var(--status-good);">· Zoho: sent</span>
                    @elseif ($production->zoho_status === 'failed')
                        <span class="ml-1 font-semibold" style="color: var(--status-critical);">· Zoho: not sent - {{ $production->zoho_error }}</span>
                        <button type="button" wire:click="retryZoho({{ $production->id }})" class="ml-1 underline font-semibold" style="color: var(--brand);">Retry</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm" style="color: var(--text-muted);">Nothing produced yet.</p>
        @endforelse
    </section>
</div>
