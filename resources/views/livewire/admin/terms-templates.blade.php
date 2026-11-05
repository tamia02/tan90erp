<?php

use App\Models\Tan90\MasterData\Customer;
use App\Models\TermsTemplate;
use App\Models\VendorMaster;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Terms & Notes: one template per vendor / customer category (Zoho Purchase
// Nature / Business Vertical), a default per side, and optional templates for
// particular vendors / customers. POs fill their T&C and Notes from here.
new #[Layout('layouts.app')] class extends Component
{
    public string $party = 'vendor';
    public ?int $editingId = null;
    public string $category = '';
    public string $name = '';
    public string $terms = '';
    public string $notes = '';
    public string $assignName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin() || auth()->user()?->holdsRole(\App\Enums\Role::StoreManager), 403);
    }

    public function updatedParty(): void
    {
        $this->newTemplate();
    }

    public function newTemplate(): void
    {
        $this->reset(['editingId', 'category', 'name', 'terms', 'notes', 'assignName']);
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $t = TermsTemplate::findOrFail($id);
        $this->party = $t->party_type;
        $this->editingId = $t->id;
        $this->category = (string) $t->category;
        $this->name = $t->name;
        $this->terms = (string) $t->terms;
        $this->notes = (string) $t->notes;
        $this->assignName = '';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'party' => ['required', Rule::in(array_keys(TermsTemplate::PARTIES))],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:150'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['name' => 'template name']);
        $t = TermsTemplate::updateOrCreate(['id' => $this->editingId], [
            'party_type' => $this->party, 'category' => $this->category ?: null, 'name' => trim($this->name),
            'terms' => $this->terms ?: null, 'notes' => $this->notes ?: null,
            'source' => $this->editingId ? TermsTemplate::find($this->editingId)->source : 'manual',
        ]);
        AuditLogger::log($this->editingId ? 'Terms template updated' : 'Terms template added', "{$t->party_type} · ".($t->category ?: 'default')." · {$t->name}", $t);
        session()->flash('success', "Saved \"{$t->name}\".");
        $this->edit($t->id);
    }

    public function toggle(int $id): void
    {
        $t = TermsTemplate::findOrFail($id);
        $t->update(['active' => ! $t->active]);
    }

    // Use this template for one particular vendor / customer (overrides category + default).
    public function assign(): void
    {
        $this->validate(['assignName' => ['required', 'string']], ['assignName.required' => 'Type the vendor / customer name.']);
        $model = $this->party === 'vendor' ? VendorMaster::where('vendor_name', trim($this->assignName)) : Customer::where('name', trim($this->assignName));
        $party = $model->first();
        if (! $party) {
            $this->addError('assignName', 'No '.$this->party.' with exactly that name - pick one from the list.');

            return;
        }
        $party->forceFill(['terms_template_id' => $this->editingId])->saveQuietly();
        AuditLogger::log('Terms template assigned', ($party->vendor_name ?? $party->name).' -> template #'.$this->editingId, $party);
        $this->assignName = '';
    }

    public function unassign(int $partyId): void
    {
        ($this->party === 'vendor' ? VendorMaster::find($partyId) : Customer::find($partyId))?->forceFill(['terms_template_id' => null])->saveQuietly();
    }

    public function with(): array
    {
        $templates = TermsTemplate::where('party_type', $this->party)->orderByRaw('category IS NOT NULL')->orderBy('category')->get();
        $isVendor = $this->party === 'vendor';

        return [
            'templates' => $templates,
            'categories' => TermsTemplate::categories($this->party),
            'assignedCounts' => ($isVendor ? VendorMaster::query() : Customer::query())->whereNotNull('terms_template_id')
                ->groupBy('terms_template_id')->selectRaw('terms_template_id, COUNT(*) c')->pluck('c', 'terms_template_id'),
            'assigned' => $this->editingId
                ? ($isVendor ? VendorMaster::where('terms_template_id', $this->editingId)->orderBy('vendor_name')->get(['id', 'vendor_name as label'])
                    : Customer::where('terms_template_id', $this->editingId)->orderBy('name')->get(['id', 'name as label']))
                : collect(),
            'partyNames' => $this->editingId
                ? ($isVendor ? VendorMaster::withAddress()->where('active', true)->orderBy('vendor_name')->pluck('vendor_name') : Customer::withAddress()->orderBy('name')->pluck('name'))
                : collect(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <h1 class="text-2xl font-bold" style="color: var(--text-primary);">Terms &amp; Notes</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Terms &amp; Conditions and Notes for each vendor / customer category ({{ TermsTemplate::CATEGORY_FIELD['vendor'] }} for vendors, {{ TermsTemplate::CATEGORY_FIELD['customer'] }} for customers, as in Zoho). A vendor or customer can also have its own. A new PO fills its T&amp;C and Notes from here: the vendor's own, else its category's, else the default.</p>
        <div class="flex gap-2 mt-3">
            @foreach (TermsTemplate::PARTIES as $key => $label)
                <button type="button" wire:click="$set('party', '{{ $key }}')" class="rounded-full border px-3 py-1.5 text-xs font-semibold" style="{{ $party === $key ? 'background: var(--brand); color: #fff; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-secondary);' }}">{{ $label }}</button>
            @endforeach
        </div>
    </section>
    @if (session('success'))<div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>@endif

    <div class="grid lg:grid-cols-5 gap-5">
        <div class="lg:col-span-2 rounded-2xl border overflow-hidden self-start" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between px-4 pt-4"><h2 class="text-base font-semibold">Templates</h2><button type="button" wire:click="newTemplate" class="text-xs font-semibold" style="color: var(--brand);">+ New template</button></div>
            @forelse ($templates as $t)
                <button type="button" wire:key="tt-{{ $t->id }}" wire:click="edit({{ $t->id }})" class="block w-full text-left px-4 py-3 border-t text-sm" style="border-color: var(--border); {{ $editingId === $t->id ? 'background: var(--brand-bg);' : '' }} {{ $t->active ? '' : 'opacity: .55;' }}">
                    <div class="font-semibold" style="color: var(--text-primary);">{{ $t->category ?: (($assignedCounts[$t->id] ?? 0) ? 'For particular '.$party.'s' : 'Default (all others)') }}</div>
                    <div class="text-xs" style="color: var(--text-muted);">{{ $t->name }}{{ ($assignedCounts[$t->id] ?? 0) ? ' · used by '.$assignedCounts[$t->id].' '.$party.'(s)' : '' }}{{ $t->active ? '' : ' · off' }}</div>
                </button>
            @empty
                <p class="px-4 py-6 text-sm" style="color: var(--text-muted);">No templates yet.</p>
            @endforelse
        </div>

        <form wire:submit="save" class="lg:col-span-3 rounded-2xl border p-5 space-y-3" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-base font-semibold">{{ $editingId ? 'Edit template' : 'New '.$party.' template' }}</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium">{{ TermsTemplate::CATEGORY_FIELD[$party] }} (category)</span>
                    <select wire:model="category" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        <option value="">Default - all other {{ $party }}s</option>
                        @foreach ($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select></label>
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Template name</span>
                    <input wire:model="name" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. Raw material vendors - Chennai">
                    @error('name') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            </div>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Notes</span>
                <textarea wire:model="notes" rows="4" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea></label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Terms &amp; Conditions</span>
                <textarea wire:model="terms" rows="10" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea></label>
            <div class="flex gap-2">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Save template</button>
                @if ($editingId)<button type="button" wire:click="toggle({{ $editingId }})" class="rounded-lg border px-4 py-2 text-sm" style="border-color: var(--border);">{{ $templates->firstWhere('id', $editingId)?->active ? 'Turn off' : 'Turn on' }}</button>@endif
            </div>

            @if ($editingId)
                <div class="border-t pt-3 mt-2" style="border-color: var(--border);">
                    <div class="text-sm font-semibold">Use this for particular {{ $party }}s</div>
                    <p class="text-xs mb-2" style="color: var(--text-muted);">These {{ $party }}s get this template instead of their category's or the default.</p>
                    <div class="flex flex-wrap gap-2 items-start">
                        <div class="flex flex-col"><input wire:model="assignName" list="tt-party-names" placeholder="Type a {{ $party }} name…" class="rounded-lg border px-3 py-2 text-sm w-72" style="border-color: var(--border);">
                            <datalist id="tt-party-names">@foreach ($partyNames as $n)<option value="{{ $n }}"></option>@endforeach</datalist>
                            @error('assignName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</div>
                        <button type="button" wire:click="assign" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Assign</button>
                    </div>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        @foreach ($assigned as $p)
                            <span wire:key="as-{{ $p->id }}" class="rounded-full border px-2.5 py-1 text-xs" style="border-color: var(--border);">{{ $p->label }} <button type="button" wire:click="unassign({{ $p->id }})" style="color: var(--status-critical);">×</button></span>
                        @endforeach
                    </div>
                </div>
            @endif
        </form>
    </div>
</div>
