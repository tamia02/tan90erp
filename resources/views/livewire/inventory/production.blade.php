<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryProduction;
use App\Models\Inventory\InventoryBundleDraft;
use App\Support\SiteLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use App\Services\AuditLogger;
use App\Models\SkuMaster;
use App\Models\ZohoLocation;
use App\Services\Inventory\InventoryService;
use App\Services\ZohoInventoryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use \App\Livewire\Concerns\PicksStockSite;

    protected function stockSiteRequired(): bool
    {
        return true;
    }

    // New SKU Master items / GRN SKUs appear here automatically.
    public function mount(string $draft = ''): void
    {
        app(\App\Services\Inventory\InventoryService::class)->syncFromMasters();
        $this->productionDate = today()->toDateString();
        $this->draftKey = (string) Str::uuid();
        $resume = (string) request()->query('draft', $draft);
        if ($resume !== '') {
            $draft = InventoryBundleDraft::where('draft_key', $resume)
                ->where('created_by', auth()->id())->firstOrFail();
            SiteLocation::assertCanAccessSiteRecord(auth()->user(), $draft->location_id);
            if ($draft->production_id) {
                $this->redirectRoute('inventory.bundles.show', $draft->production_id);
                return;
            }
            $this->draftKey = $draft->draft_key;
            foreach ($draft->payload as $key => $value) {
                if (in_array($key, self::DRAFT_FIELDS, true)) $this->$key = $value;
            }
            $this->stockSite = (string) ($draft->location_id ?? '');
        }
    }

    private const DRAFT_FIELDS = ['fgItemId', 'qty', 'productionDate', 'materials', 'notes', 'zohoLocationId', 'materialKeySequence'];

    #[Locked]
    public string $draftKey = '';

    #[Url(as: 'draft')]
    public string $resumeDraft = '';

    public string $fgItemId = '';
    public string $qty = '';
    public string $productionDate = '';
    // Batch-only material rows. Recipe rows preload here, but edits never alter the saved recipe.
    public array $materials = [];
    public string $materialSearch = '';
    public int $materialKeySequence = 1;
    public string $notes = '';
    public string $zohoLocationId = '';

    public function saveDraft(): void
    {
        $this->validate([
            'fgItemId' => ['nullable', 'exists:inventory_items,id'],
            'qty' => ['nullable', 'numeric', 'min:0'],
            'productionDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'materials' => ['array', 'max:100'],
            'materials.*.item_id' => ['nullable', 'exists:inventory_items,id'],
            'materials.*.per_unit' => ['nullable', 'numeric', 'min:0'],
            'materials.*.wastage' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->persistDraft();
        session()->flash('success', 'Draft saved. No stock has been changed. You can resume it from My bundle drafts.');
    }

    private function persistDraft(): InventoryBundleDraft
    {
        $site = SiteLocation::current() ?? ($this->stockSite !== '' ? (int) $this->stockSite : null);
        SiteLocation::assertCanAccessSiteRecord(auth()->user(), $site);
        if ($site !== null) abort_unless(SiteLocation::visibleLocations()->contains('id', $site), 403);
        $payload = [];
        foreach (self::DRAFT_FIELDS as $key) $payload[$key] = $this->$key;

        return DB::transaction(function () use ($site, $payload) {
            $draft = InventoryBundleDraft::firstOrCreate(['draft_key' => $this->draftKey], [
                'created_by' => auth()->id(), 'location_id' => $site, 'payload' => $payload,
            ]);
            $draft = InventoryBundleDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $draft->created_by === (int) auth()->id(), 403);
            SiteLocation::assertCanAccessSiteRecord(auth()->user(), $draft->location_id);
            if (! $draft->production_id) $draft->update(['location_id' => $site, 'payload' => $payload]);
            return $draft;
        });
    }

    public function updatedFgItemId(): void
    {
        $fg = $this->fgItemId ? InventoryItem::with('bomLines')->find($this->fgItemId) : null;
        $this->materials = $fg ? $fg->bomLines->map(fn ($line) => [
            'row_key' => 'bundle-material-'.$this->materialKeySequence++,
            'item_id' => (string) $line->component_item_id,
            'per_unit' => rtrim(rtrim((string) $line->qty_per_unit, '0'), '.'),
            'wastage' => '',
        ])->values()->all() : [];
        $this->materialSearch = '';
        $this->resetValidation();
    }

    public function addMaterial(): void
    {
        $this->materials[] = ['row_key' => 'bundle-material-'.$this->materialKeySequence++, 'item_id' => '', 'per_unit' => '', 'wastage' => ''];
    }

    public function removeMaterial(int $index): void
    {
        unset($this->materials[$index]);
        $this->materials = array_values($this->materials);
        $this->resetValidation();
    }

    public function updatedMaterials(mixed $value, ?string $key = null): void
    {
        if (preg_match('/^(\d+)\.item_id$/', (string) $key, $match) && filled($value)) {
            $fg = $this->fgItemId ? InventoryItem::with('bomLines')->find($this->fgItemId) : null;
            $recipeLine = $fg?->bomLines->firstWhere('component_item_id', (int) $value);
            if ($recipeLine) {
                $this->materials[(int) $match[1]]['per_unit'] = rtrim(rtrim((string) $recipeLine->qty_per_unit, '0'), '.');
            }
        }
        $this->resetValidation();
    }

    // Changing qty/wastage invalidates the last "Not enough ..." message.
    public function updated(string $property): void
    {
        if ($property === 'qty' || str_starts_with($property, 'materials')) {
            $this->resetValidation();
        }
    }

    public function produce(InventoryService $inventory): void
    {
        $inventory = $this->inventory(); // Create Bundle at the chosen / own site
        $this->validate([
            'fgItemId' => ['required', 'exists:inventory_items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'productionDate' => ['required', 'date'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'materials.*.per_unit' => ['required', 'numeric', 'gt:0'],
            'materials.*.wastage' => ['nullable', 'numeric', 'min:0'],
            'zohoLocationId' => [$this->sendsToZoho() ? 'required' : 'nullable', 'string'],
        ], ['fgItemId.required' => 'Pick the Finished Good to make.', 'qty.gt' => 'Quantity must be more than 0.', 'zohoLocationId.required' => 'Choose the Zoho warehouse this bundle is made in.']);

        $sendsToZoho = $this->sendsToZoho();
        $fgItem = InventoryItem::with('bomLines')->findOrFail($this->fgItemId);
        $componentIds = collect($this->materials)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        if (count($componentIds) !== count(array_unique($componentIds))) {
            $this->addError('materials', 'Choose each material only once.');
            return;
        }
        $perUnit = collect($this->materials)->mapWithKeys(fn ($line) => [(int) $line['item_id'] => (float) $line['per_unit']])->all();
        $wastage = collect($this->materials)->mapWithKeys(fn ($line) => [(int) $line['item_id'] => (float) ($line['wastage'] ?: 0)])->all();
        $draft = $this->persistDraft();
        $alreadyPosted = false;
        $production = DB::transaction(function () use ($draft, $inventory, $fgItem, $perUnit, $wastage, $componentIds, &$alreadyPosted) {
            $locked = InventoryBundleDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->production_id) { $alreadyPosted = true; return InventoryProduction::findOrFail($locked->production_id); }
            $posted = $inventory->produce($fgItem, (float) $this->qty, $wastage, $this->notes ?: null, auth()->user(), $perUnit, $componentIds, $this->productionDate);
            $locked->update(['production_id' => $posted->id]);
            return $posted;
        });
        $fg = $production->fgItem;
        AuditLogger::log('Production posted', "{$production->number} · {$production->qty_produced} {$fg->unit} {$fg->safeDisplayName()}", $production);

        $zohoNote = '';
        if ($sendsToZoho && ! $alreadyPosted) {
            $production->forceFill(['zoho_location_id' => $this->zohoLocationId])->save();
            $status = app(ZohoInventoryService::class)->pushBundle($production);
            $zohoNote = $status === 'sent' ? ' Bundle created in Zoho.' : ' Not sent to Zoho: '.$production->fresh()->zoho_error.' (use Retry below).';
        }

        session()->flash('success', "{$production->number}: made {$this->qty} {$fg->unit} of {$fg->safeDisplayName()}. Raw/packaging material and wastage have been deducted.".$zohoNote);
        $this->redirectRoute('inventory.bundles.show', $production->id);
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
        $inventory = $this->inventory();
        $fgItems = InventoryItem::where('item_type', 'fg')->where('active', true)->with('bomLines.component')->orderBy('sku')->get();
        $selected = $this->fgItemId ? $fgItems->firstWhere('id', (int) $this->fgItemId) : null;
        $componentIds = collect($this->materials)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $components = $componentIds->isEmpty() ? collect() : InventoryItem::whereIn('id', $componentIds)->get();
        $search = trim($this->materialSearch);
        $term = '%'.$search.'%';
        $materialOptions = InventoryItem::query()->whereIn('item_type', ['rm', 'pm'])
            ->where(function ($query) use ($componentIds, $search, $term) {
                $query->whereIn('id', $componentIds);
                if ($search === '') {
                    $query->orWhere('active', true);
                } else {
                    $query->orWhere(fn ($active) => $active->where('active', true)->where(fn ($matches) => $matches
                        ->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('sku', 'like', $term)));
                }
            })
            ->orderByRaw("CASE WHEN code IS NULL OR code = '' THEN 1 ELSE 0 END")
            ->orderBy('code')->orderBy('sku')->get();

        return [
            ...$this->stockSiteView(),
            'fgItems' => $fgItems,
            'selected' => $selected,
            'componentBalances' => $inventory->balances($components),
            // Keep archived ingredients visible when they are already part of
            // this recipe/batch. New selections remain limited to active items.
            'materialOptions' => $materialOptions,
            'sendsToZoho' => $this->sendsToZoho(),
            'zohoLocations' => ZohoLocation::where('active', true)->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.production', 'title' => $resumeDraft ? 'Edit Bundle Draft' : 'Create Bundle', 'subtitle' => 'Choose a finished good, review the recipe and stock, then save a draft or create the bundle.', 'actionUrl' => route('inventory.production'), 'actionLabel' => 'All Bundles'])
    @include('partials.stock-site-picker', ['required' => true])

    {{-- Inputs use deferred wire:model so a fast click on "Create Bundle" always sends the typed qty/wastage; the debounced $refresh only redraws the preview. (wire:model.live.debounce lost values typed <400ms before the click.) --}}
    <form wire:submit="produce" class="rounded-2xl border p-5 space-y-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="grid sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1 text-sm sm:col-span-2"><span class="font-medium">Finished Good to make</span>
                <select wire:model.live="fgItemId" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                    <option value="">Select…</option>
                    @foreach ($fgItems as $fg)<option value="{{ $fg->id }}">{{ $fg->visibleNameFor(auth()->user()) }}{{ $fg->bomLines->isEmpty() ? ' (no recipe yet)' : '' }}</option>@endforeach
                </select>
                @error('fgItemId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Quantity to make{{ $selected ? " ({$selected->unit})" : '' }}</span>
                <input wire:model="qty" type="number" step="0.001" min="0" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                @error('qty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Assembly date</span>
                <input wire:model.blur="productionDate" type="date" required class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                @error('productionDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
        </div>

        @if ($fgItems->isEmpty())
            <p class="text-sm" style="color: var(--status-warning);">No Finished Goods yet - add one in <a href="{{ route('inventory.items') }}" wire:navigate class="underline">Items</a>.</p>
        @endif

        @if ($selected)
            <p class="text-xs" style="color: var(--text-muted);">Recipe materials are prefilled. Add or remove rows for this batch; the saved recipe stays unchanged.</p>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Search bundle items</span>
                <input wire:model.live.debounce.300ms="materialSearch" type="search" placeholder="Filter materials by item name or code…" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
            </label>
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[760px]">
                    <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3 text-right">Per 1 {{ $selected->unit }}</th><th class="py-2 pr-3 text-right">Will be used</th><th class="py-2 pr-3">Wastage</th><th class="py-2 pr-3 text-right">In stock</th><th class="py-2 text-right">Left after</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($materials as $index => $material)
                            @php
                                $component = $materialOptions->firstWhere('id', (int) ($material['item_id'] ?? 0));
                                $componentId = (int) ($material['item_id'] ?? 0);
                                $perPiece = (float) ($material['per_unit'] ?? 0);
                                $use = round($perPiece * (float) ($qty ?: 0), 3);
                                $waste = (float) ($material['wastage'] ?? 0);
                                $have = $componentBalances[$componentId]['final'] ?? 0;
                                $left = round($have - $use - $waste, 3);
                            @endphp
                            <tr wire:key="{{ $material['row_key'] ?? 'bundle-material-'.$index }}" style="border-top: 1px solid var(--border);">
                                <td class="py-2 pr-3 min-w-64"><select wire:model.live="materials.{{ $index }}.item_id" aria-label="Item {{ $index + 1 }}" class="rounded-lg border px-2 py-1 w-full" style="border-color: var(--border);"><option value="">Select item…</option>@foreach ($materialOptions as $option)<option value="{{ $option->id }}" @if ($option->id !== $componentId && collect($materials)->contains(fn ($row) => (int) ($row['item_id'] ?? 0) === $option->id) ) disabled @endif>{{ $option->code ?: 'ITEM-'.$option->id }} · {{ $option->typeLabel() }}</option>@endforeach</select>
                                    @error('materials.'.$index.'.item_id')<div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div>@enderror</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap"><input wire:model="materials.{{ $index }}.per_unit" type="number" step="any" min="0.000001" class="rounded-lg border px-2 py-1 w-24 text-right" style="border-color: var(--border);" @disabled(!$component)> {{ $component?->unit }}
                                    @error('materials.'.$index.'.per_unit')<div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div>@enderror</td>
                                <td class="py-2 pr-3 text-right font-semibold">{{ $component ? $use.' '.$component->unit : '—' }}</td>
                                <td class="py-2 pr-3"><input wire:model="materials.{{ $index }}.wastage" type="number" step="0.001" min="0" placeholder="0" class="rounded-lg border px-2 py-1 w-24" style="border-color: var(--border);" @disabled(!$component)></td>
                                <td class="py-2 pr-3 text-right">{{ $component ? ($have.' '.$component->unit) : '—' }}</td>
                                <td class="py-2 text-right font-semibold" style="color: {{ $left < 0 ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $component ? ($left < 0 ? 'Short by '.abs($left) : $left).' '.$component->unit : '—' }}</td>
                                <td class="py-2 pl-2 text-right"><button type="button" wire:click="removeMaterial({{ $index }})" class="text-xs font-medium" style="color: var(--status-critical);">Remove</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('materials')<p class="text-xs" style="color: var(--status-critical);">{{ $message }}</p>@enderror
            <button type="button" wire:click="addMaterial" class="text-sm font-semibold" style="color: var(--brand);">+ Add Item</button>
            @if ($sendsToZoho)
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Zoho warehouse *</span>
                    <select wire:model="zohoLocationId" class="rounded-lg border px-3 py-2" style="border-color: var(--border);"><option value="">Select the warehouse this is made in…</option>@foreach ($zohoLocations as $location)<option value="{{ $location->zoho_location_id }}">{{ $location->name }}</option>@endforeach</select>
                    <span class="text-xs" style="color: var(--text-muted);">This bundle will also be created in Zoho Inventory, so Zoho's stock moves the same way.</span>
                    @error('zohoLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Notes (optional)</span><input wire:model="notes" class="rounded-lg border px-3 py-2" style="border-color: var(--border);" placeholder="Batch / shift / remarks"></label>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Create Bundle &amp; update stock</button>
        @endif
        <div class="flex flex-wrap gap-3 items-center">
            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color:var(--border);">Save as Draft</button>
            <a href="{{ route('inventory.production') }}" class="rounded-lg border px-4 py-2 text-sm" style="border-color:var(--border);">Cancel</a>
        </div>
    </form>

</div>
