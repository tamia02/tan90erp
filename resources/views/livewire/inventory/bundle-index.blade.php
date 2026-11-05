<?php

use App\Models\Inventory\InventoryBundleDraft;
use App\Models\Inventory\InventoryProduction;
use App\Support\SiteLocation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use \App\Livewire\Concerns\PicksStockSite;
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        if (request()->filled('draft')) {
            $this->redirectRoute('inventory.production.create', ['draft' => request()->query('draft')]);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStockSite(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $site = SiteLocation::current();
        $term = '%'.trim($this->search).'%';

        return [
            ...$this->stockSiteView(),
            'bundles' => InventoryProduction::with(['fgItem', 'creator'])
                ->when($site !== null, fn ($q) => $q->where('location_id', $site))
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($match) => $match
                    ->where('number', 'like', $term)
                    ->orWhereHas('fgItem', fn ($fg) => $fg->where('name', 'like', $term)->orWhere('code', 'like', $term))))
                ->latest('id')->paginate(200),
            'drafts' => InventoryBundleDraft::where('created_by', auth()->id())->whereNull('production_id')
                ->when($site !== null, fn ($q) => $q->where('location_id', $site))
                ->latest('updated_at')->get(),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.production', 'title' => 'Bundle', 'subtitle' => 'Drafts and completed inventory bundles.', 'actionUrl' => route('inventory.production.create'), 'actionLabel' => 'Create Bundle'])
    @include('partials.stock-site-picker', ['required' => false])

    @if ($drafts->isNotEmpty())
        <section class="rounded-2xl border p-5 mb-5" style="background:var(--surface-3);border-color:var(--border);">
            <h2 class="text-lg font-semibold mb-3">Saved drafts</h2>
            @foreach ($drafts as $draft)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t py-3 text-sm" style="border-color:var(--border);" wire:key="bundle-draft-{{ $draft->id }}">
                    <span>Draft #{{ $draft->id }} · {{ $draft->payload['qty'] ?: 'Quantity not entered' }} · {{ $draft->updated_at->format('d M Y, H:i') }}</span>
                    <a class="font-semibold underline" href="{{ route('inventory.production.create', ['draft' => $draft->draft_key]) }}">Resume Draft</a>
                </div>
            @endforeach
        </section>
    @endif

    <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-lg font-semibold">Created bundles</h2>
            <input wire:model.live.debounce.400ms="search" type="search" aria-label="Search bundles" placeholder="Search bundle number or product" class="rounded-lg border px-3 py-2 text-sm w-full sm:w-72" style="border-color:var(--border);">
        </div>
        <p class="text-xs mb-3" style="color:var(--text-muted);">{{ $bundles->total() }} matching bundles · 200 per page</p>
        @forelse ($bundles as $bundle)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t py-3 text-sm" style="border-color:var(--border);" wire:key="bundle-{{ $bundle->id }}">
                <div><span class="font-semibold">{{ $bundle->number }}</span> · {{ $bundle->fgItem?->visibleNameFor(auth()->user()) }} · {{ $bundle->qty_produced }} {{ $bundle->fgItem?->unit }}
                    <div class="text-xs" style="color:var(--text-muted);">{{ $bundle->production_date?->format('d M Y') }} · {{ $bundle->creator?->name }}</div>
                </div>
                <div class="flex gap-3 font-semibold"><a href="{{ route('inventory.bundles.show', $bundle) }}" class="underline">View</a><a href="{{ route('inventory.bundles.edit', $bundle) }}" class="underline">Edit</a></div>
            </div>
        @empty
            <p class="py-8 text-center text-sm" style="color:var(--text-muted);">No bundles match this search.</p>
        @endforelse
        <div class="mt-4">{{ $bundles->links() }}</div>
    </section>
</div>
