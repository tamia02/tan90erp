<?php

use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Recipes only: which RM / PM (and how much) makes 1 unit of each Finished
// Good. Items themselves are managed on the Items page.
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        app(InventoryService::class)->syncFromMasters();
    }

    public string $search = '';
    public string $recipeFilter = '';
    public int $perPage = 100;
    public ?int $recipeFgId = null;
    public string $componentId = '';
    public string $componentQty = '';

    public function updatedSearch(): void
    {
        $this->perPage = 100;
    }

    public function updatedRecipeFilter(): void
    {
        $this->perPage = 100;
    }

    public function openRecipe(int $fgId): void
    {
        $this->recipeFgId = $fgId;
        $this->reset(['componentId', 'componentQty']);
        $this->resetValidation();
    }

    public function addComponent(): void
    {
        $this->validate([
            'componentId' => ['required', Rule::exists('inventory_items', 'id')->whereIn('item_type', ['rm', 'pm'])],
            'componentQty' => ['required', 'numeric', 'gt:0'],
        ], ['componentId.required' => 'Pick a Raw or Packaging material.', 'componentQty.gt' => 'Quantity must be more than 0.']);

        $line = InventoryBomLine::updateOrCreate(
            ['fg_item_id' => $this->recipeFgId, 'component_item_id' => (int) $this->componentId],
            ['qty_per_unit' => $this->componentQty],
        );
        AuditLogger::log('Recipe line saved', "FG #{$this->recipeFgId} · component #{$line->component_item_id} · {$line->qty_per_unit} per unit", $line);
        $this->reset(['componentId', 'componentQty']);
    }

    public function removeComponent(int $lineId): void
    {
        InventoryBomLine::where('fg_item_id', $this->recipeFgId)->whereKey($lineId)->delete();
    }

    public function with(): array
    {
        $query = InventoryItem::query()->where('item_type', 'fg')->where('active', true)
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('code', 'like', '%'.trim($this->search).'%')
                ->when(InventoryItem::canSeeSkuName(auth()->user()), fn ($w) => $w->orWhere('sku', 'like', '%'.trim($this->search).'%'), fn ($w) => $w->orWhere(fn ($n) => $n->whereNull('name')->where('sku', 'like', '%'.trim($this->search).'%')))))
            ->when($this->recipeFilter === 'with', fn ($q) => $q->has('bomLines'))
            ->when($this->recipeFilter === 'missing', fn ($q) => $q->doesntHave('bomLines'));
        $total = (clone $query)->count();
        $allFg = InventoryItem::where('item_type', 'fg')->where('active', true);

        return [
            'fgs' => $query->with('bomLines.component')->withCount('bomLines')->orderBy('sku')->limit($this->perPage)->get(),
            'total' => $total,
            'withRecipe' => (clone $allFg)->has('bomLines')->count(),
            'missing' => (clone $allFg)->doesntHave('bomLines')->count(),
            'recipeFg' => $this->recipeFgId ? InventoryItem::with('bomLines.component')->find($this->recipeFgId) : null,
            'componentOptions' => $this->recipeFgId ? InventoryItem::whereIn('item_type', ['rm', 'pm'])->where('active', true)->orderBy('sku')->get() : collect(),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.recipes', 'title' => 'Composite Items (Recipes)', 'subtitle' => 'One recipe per Finished Good: which Raw Material (RM) and Packaging Material (PM) make 1 unit, and how much. Recipes from Zoho bundles are filled in automatically; you can add or change lines here.'])

    <div class="flex flex-wrap items-center gap-2 mb-3">
        <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search finished goods…" class="rounded-lg border px-3 py-2 text-sm flex-1 min-w-[200px]" style="border-color: var(--border);">
        <select wire:model.live="recipeFilter" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            <option value="">All Finished Goods ({{ $withRecipe + $missing }})</option>
            <option value="with">With recipe ({{ $withRecipe }})</option>
            <option value="missing">No recipe yet ({{ $missing }})</option>
        </select>
        <span class="text-xs" style="color: var(--text-muted);">Showing {{ $fgs->count() }} of {{ $total }}</span>
    </div>

    <div class="grid lg:grid-cols-3 gap-5">
        <div class="{{ $recipeFg ? 'lg:col-span-2' : 'lg:col-span-3' }} space-y-3">
            <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm min-w-[640px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="px-4 py-3">Finished Good</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3 text-right">Materials</th><th class="px-4 py-3">Recipe (for 1 unit)</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse ($fgs as $fg)
                            <tr wire:key="recipe-fg-{{ $fg->id }}" style="border-top: 1px solid var(--border);{{ $recipeFgId === $fg->id ? ' background: var(--brand-bg);' : '' }}">
                                <td class="px-4 py-3"><div class="font-medium" style="color: var(--text-primary);">{{ $fg->visibleNameFor(auth()->user()) }}</div></td>
                                <td class="px-4 py-3">{{ $fg->unit }}</td>
                                <td class="px-4 py-3 text-right">{{ $fg->bom_lines_count }}</td>
                                <td class="px-4 py-3 text-xs">
                                    @forelse ($fg->bomLines as $line){{ rtrim(rtrim((string) $line->qty_per_unit, '0'), '.') }} {{ $line->component?->unit }} {{ $line->component?->visibleNameFor(auth()->user()) }}@if (! $loop->last), @endif @empty <span style="color: var(--status-warning);">No recipe yet</span> @endforelse
                                </td>
                                <td class="px-4 py-3 text-right"><button type="button" wire:click="openRecipe({{ $fg->id }})" class="text-xs font-semibold" style="color: var(--brand);">{{ $fg->bom_lines_count ? 'Edit recipe' : 'Add recipe' }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center" style="color: var(--text-muted);">No Finished Goods here. Mark an item as Finished Good (FG) on the Items page first.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($fgs->count() < $total)
                <button type="button" wire:click="$set('perPage', {{ $perPage + 100 }})" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Show more ({{ $total - $fgs->count() }} more)</button>
            @endif
        </div>

        @if ($recipeFg)
            <div class="rounded-2xl border p-5 space-y-3 self-start" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-2"><h2 class="text-lg font-semibold" style="color: var(--text-primary);">1 {{ $recipeFg->unit }} of {{ $recipeFg->visibleNameFor(auth()->user()) }} needs</h2><button type="button" wire:click="$set('recipeFgId', null)" class="text-xs" style="color: var(--text-muted);">Close</button></div>
                @forelse ($recipeFg->bomLines as $line)
                    <div wire:key="bom-{{ $line->id }}" class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        <span>{{ rtrim(rtrim((string) $line->qty_per_unit, '0'), '.') }} {{ $line->component?->unit }} of {{ $line->component?->visibleNameFor(auth()->user()) }} <span class="text-xs" style="color: var(--text-muted);">({{ $line->component?->typeLabel() }})</span></span>
                        <button type="button" wire:click="removeComponent({{ $line->id }})" class="text-xs font-semibold" style="color: var(--status-critical);">Remove</button>
                    </div>
                @empty
                    <p class="text-sm" style="color: var(--status-warning);">No materials yet - add the first one below.</p>
                @endforelse
                <form wire:submit="addComponent" class="space-y-2">
                    <select wire:model="componentId" class="rounded-lg border px-3 py-2 text-sm w-full" style="border-color: var(--border);"><option value="">Pick RM / PM…</option>@foreach ($componentOptions as $option)<option value="{{ $option->id }}">{{ $option->visibleNameFor(auth()->user()) }} ({{ $option->unit }})</option>@endforeach</select>
                    @error('componentId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    <input wire:model="componentQty" type="number" step="0.0001" min="0" placeholder="Qty per 1 {{ $recipeFg->unit }}" class="rounded-lg border px-3 py-2 text-sm w-full" style="border-color: var(--border);">
                    @error('componentQty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to recipe</button>
                </form>
            </div>
        @endif
    </div>
</div>
