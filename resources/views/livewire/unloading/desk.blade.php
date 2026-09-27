<?php

use App\Models\GateEntry;
use App\Models\UnloadingPackingUnit;
use App\Models\UnloadingRecord;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public ?int $starting = null;

    public string $manpowerCount = '';

    public ?int $completing = null;

    public ?int $loadingCompleting = null;

    public array $documentsChecked = ['invoice' => false, 'bill' => false, 'pod' => false, 'lr' => false];

    public bool $documentsPackConfirmed = false;

    public string $boxCount = '';

    public string $packingUnit = 'Box';

    public string $podLrRef = '';

    public $podFile = null;

    public array $stagingAreas = ['Staging Bay 1', 'Staging Bay 2', 'Staging Bay 3', 'Staging Bay 4'];

    public function openComplete(int $gateId): void
    {
        $this->completing = $gateId;
        $activeUnits = UnloadingPackingUnit::where('active', true)->orderBy('sort_order')->pluck('name');
        if (! $activeUnits->contains($this->packingUnit)) {
            $this->packingUnit = (string) $activeUnits->first();
        }
    }

    // Staging bay is no longer picked by hand — the least-occupied bay is
    // located automatically the moment a vehicle is allotted.
    private function autoLocateStagingArea(): string
    {
        return collect($this->stagingAreas)
            ->sortBy(fn ($area) => UnloadingRecord::whereNull('completed_at')->where('staging_area', $area)->count())
            ->first();
    }

    public function allot(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        // Guards against a double-click (or stale re-render) re-submitting
        // an already-allotted gate — without this, the second submit hits
        // unloading_records' unique gate_entry_id constraint and 500s.
        if ($gate->status !== 'dock_assigned' || $gate->unloadingRecord) {
            return;
        }

        $stagingArea = $this->autoLocateStagingArea();

        UnloadingRecord::create([
            'gate_entry_id' => $gate->id,
            'box_count' => 0,
            'staging_area' => $stagingArea,
            'unloaded_by' => auth()->user()->name,
            'allotted_at' => now(),
        ]);

        $gate->update(['status' => 'allotted']);

        AuditLogger::log('Allotted for unloading', "{$gate->gate_no} · auto-located to {$stagingArea}", $gate);
        session()->flash('success', "{$gate->gate_no} assigned to {$stagingArea}. It is ready for the unloading team.");
    }

    // Confirmed missing against spec: "Store Manager aligns manpower and
    // unloading is done" had no manpower concept anywhere -- Start unloading
    // now requires a headcount first, matching that step.
    public function startUnloading(int $gateId): void
    {
        $this->validate(['manpowerCount' => ['required', 'integer', 'min:1']]);

        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'allotted' || ! $gate->unloadingRecord) {
            $this->reset(['starting', 'manpowerCount']);

            return;
        }

        $gate->unloadingRecord->update(['started_at' => now(), 'manpower_count' => $this->manpowerCount]);
        $gate->update(['status' => 'unloading']);

        AuditLogger::log('Unloading started', "{$gate->gate_no} · {$this->manpowerCount} crew assigned", $gate);
        session()->flash('success', "Unloading started for {$gate->gate_no} with {$this->manpowerCount} team members.");

        // Completion data belongs to the next stage. Clear any stale browser
        // state so the same physical-count/POD values are never shown twice.
        $this->reset(['starting', 'manpowerCount', 'boxCount', 'packingUnit', 'podLrRef', 'podFile']);
        $this->resetValidation();

        // Move directly to the one completion step after the start action.
        // This makes the transition visible and prevents the start form from
        // appearing again after the Livewire refresh.
        $this->openComplete($gateId);
    }

    public function completeUnloading(int $gateId): void
    {
        // Check the workflow state before validating the upload form. A
        // double-click or a stale Livewire request after the first successful
        // completion must be a harmless acknowledgement, not a second copy
        // of the same four-question checklist.
        $gate = GateEntry::with('unloadingRecord')->findOrFail($gateId);
        if ($gate->status !== 'unloading' || ! $gate->unloadingRecord) {
            $this->reset(['completing', 'boxCount', 'packingUnit', 'podLrRef', 'podFile']);
            if ($gate->status === 'grn' || $gate->unloadingRecord?->completed_at) {
                session()->flash('success', "{$gate->gate_no} unloading was already completed and sent to QC.");
            }

            return;
        }

        // min:0 previously let a completed unloading record "0 boxes" --
        // confirmed live, accepted outright.
        $this->validate([
            'boxCount' => ['required', 'integer', 'min:1'],
            'packingUnit' => ['required', 'string', Rule::in(UnloadingPackingUnit::where('active', true)->pluck('name')->all())],
            'podFile' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'podLrRef' => ['nullable', 'string', 'max:255'],
        ]);

        $podPath = $this->podFile?->store('unloading-pods', 'local');

        DB::transaction(function () use ($gate, $podPath): void {
            $lockedGate = GateEntry::whereKey($gate->id)->lockForUpdate()->firstOrFail();
            $record = UnloadingRecord::where('gate_entry_id', $lockedGate->id)->lockForUpdate()->firstOrFail();

            // A second click/request after the first completion is a harmless
            // no-op, never a duplicate write or a 500 response.
            if ($lockedGate->status !== 'unloading' || $record->completed_at) {
                return;
            }

            $record->update([
                'box_count' => $this->boxCount,
                'packing_unit' => $this->packingUnit,
                'pod_lr_ref' => $this->podLrRef ?: null,
                'pod_document_path' => $podPath,
                'completed_at' => now(),
            ]);

            // 'grn' means "ready for QC Check" — GRN Check/posting is a
            // separate, later step owned by Store Manager.
            $lockedGate->update(['status' => 'grn']);
        });

        AuditLogger::log('Unloading completed, sent to QC Check', $gate->gate_no, $gate);

        AuditLogger::log('POD captured and unloading completed', "{$gate->gate_no} · {$this->boxCount} {$this->packingUnit}", $gate);
        session()->flash('success', "{$gate->gate_no} unloading completed and sent to QC.");

        $this->reset(['completing', 'boxCount', 'packingUnit', 'podLrRef', 'podFile']);
    }

    public function startLoading(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'dock_assigned' || $gate->entry_type !== 'outward' || $gate->loading_started_at) {
            return;
        }

        // Loading-window dates are planning information only. A vehicle that
        // has been approved and assigned a dock must not be blocked because a
        // window was missed, entered in another timezone, or left blank.
        $this->resetValidation('loadingWindow');

        $gate->update(['loading_started_at' => now()]);
        AuditLogger::log('Outward loading started', "{$gate->gate_no} · {$gate->loading_dock}", $gate);
        session()->flash('success', "{$gate->gate_no} loading started. Complete the document handover checklist; then Security Guard must confirm vehicle exit.");
    }

    public function openOutwardCompletion(int $gateId): void
    {
        $this->loadingCompleting = $gateId;
        $this->documentsChecked = ['invoice' => false, 'bill' => false, 'pod' => false, 'lr' => false];
        $this->documentsPackConfirmed = false;
    }

    public function completeLoading(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        $this->validate([
            'documentsChecked.invoice' => ['accepted'],
            'documentsChecked.bill' => ['accepted'],
            'documentsChecked.pod' => ['accepted'],
            'documentsChecked.lr' => ['accepted'],
            'documentsPackConfirmed' => ['accepted'],
        ], [
            'documentsChecked.invoice.accepted' => 'Confirm the Invoice was handed to the driver.',
            'documentsChecked.bill.accepted' => 'Confirm the Bill was handed to the driver.',
            'documentsChecked.pod.accepted' => 'Confirm the POD documents were handed to the driver.',
            'documentsChecked.lr.accepted' => 'Confirm the LR copy was handed to the driver.',
            'documentsPackConfirmed.accepted' => 'Confirm the complete dispatch document pack was handed to the driver.',
        ]);

        if ($gate->status !== 'dock_assigned' || $gate->entry_type !== 'outward' || ! $gate->loading_started_at) {
            $this->reset(['loadingCompleting', 'documentsChecked', 'documentsPackConfirmed']);

            return;
        }

        $gate->update([
            'status' => 'loaded',
            'loaded_at' => now(),
            'dispatch_documents_shared' => true,
        ]);

        AuditLogger::log('Outward loading complete, document checklist handed to driver', $gate->gate_no, $gate);
        session()->flash('success', "{$gate->gate_no} loaded and documents shared. Guard must confirm the vehicle exit.");
        $this->reset(['loadingCompleting', 'documentsChecked', 'documentsPackConfirmed']);
    }

    public function with(): array
    {
        return [
            // Same entry_type filter as Loading Desk, for the same reason:
            // visitor/outward entries never have unloading to do.
            'toAllot' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'inward')->orderByDesc('dock_assigned_at')->get(),
            'toStart' => GateEntry::with('unloadingRecord')->where('status', 'allotted')->where('entry_type', 'inward')->orderByDesc('created_at')->get(),
            'inProgress' => GateEntry::with('unloadingRecord')->where('status', 'unloading')->where('entry_type', 'inward')->orderByDesc('created_at')->get(),
            'outwardReadyCount' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->count(),
            'outwardReadyToLoad' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->orderByDesc('dock_assigned_at')->get(),
            'outwardHistory' => GateEntry::where('entry_type', 'outward')->whereNotNull('loaded_at')->orderByDesc('loaded_at')->limit(10)->get(),
            'history' => UnloadingRecord::with('gateEntry')->orderByDesc('created_at')->limit(10)->get(),
            'packingUnits' => UnloadingPackingUnit::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive · Shared work area</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Loading &amp; Unloading</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Inward unloading and outward loading are together here. Choose a workstream below; each keeps its own queue and actions.</p>
        <nav aria-label="Loading and unloading workstreams" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
            <a href="#inward-unloading" class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold transition hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--brand-bg); border-color: var(--brand); color: var(--brand);">
                <span>Inward · Unloading</span><span class="rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-1);">{{ $toAllot->count() + $toStart->count() + $inProgress->count() }} active</span>
            </a>
            <a href="#outward-loading" class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold transition hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                <span>Outward · Loading</span><span class="rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">{{ $outwardReadyCount }} active</span>
            </a>
        </nav>
    </section>

    <h2 id="inward-unloading" class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Inward unloading · To allot</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toAllot as $g)
            <div class="rounded-lg border p-4 flex items-center justify-between gap-3" style="background: var(--surface-3); border-color: var(--border);">
                <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · On {{ $g->loading_dock }}</div>
                </a>
                <button wire:click="allot({{ $g->id }})" wire:loading.attr="disabled" wire:target="allot({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Allot &amp; auto-locate bay</button>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to be allotted.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Ready to start · complete unloading workspace</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toStart as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · Allotted to {{ $g->unloadingRecord?->staging_area }}</div>
                    </a>
                    @if ($starting !== $g->id)
                        <button wire:click="$set('starting', {{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--brand);">Open start step</button>
                    @endif
                </div>
                @if ($starting === $g->id)
                    <div class="rounded-lg border p-3 mt-4" style="border-color: var(--border); background: var(--surface-1);">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">Start unloading</div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Assign the unloading crew and start the physical work. After unloading is finished, the completion checklist will be shown once for the physical count and signed POD.</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Ready to start</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Manpower assigned <span aria-hidden="true">*</span></span>
                            <input wire:model="manpowerCount" type="number" min="1" inputmode="numeric" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="No. of workers" aria-label="Manpower assigned" />
                            @error('manpowerCount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="flex gap-2 sm:col-span-2">
                            <button wire:click="startUnloading({{ $g->id }})" wire:loading.attr="disabled" wire:target="startUnloading({{ $g->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Start unloading</button>
                            <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="rounded-lg border px-3.5 py-2 text-sm font-medium" style="border-color: var(--border); color: var(--text-primary);">View shipment details</a>
                        </div>
                    </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to start.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">In progress · finish the same workspace</h2>
    <div class="flex flex-col gap-2">
        @forelse ($inProgress as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 mb-2 flex-wrap">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · {{ $g->unloadingRecord?->manpower_count }} crew</div>
                    </a>
                    @if ($completing !== $g->id)
                        <button wire:click="openComplete({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border transition hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--text-primary);">Open completion checklist</button>
                    @endif
                </div>
                @if ($completing === $g->id)
                    <div class="rounded-lg border p-3 mt-3" style="border-color: var(--border); background: var(--surface-1);">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">Full unloading checklist</div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Confirm the physical result and attach the signed POD. This will move the shipment to QC Check.</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Started {{ $g->unloadingRecord?->started_at?->format('d M, H:i') }}</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Physical count</span>
                            <input wire:model="boxCount" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('boxCount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Packing unit</span>
                            <select wire:model="packingUnit" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                @foreach ($packingUnits as $unit) <option value="{{ $unit }}">{{ $unit }}</option> @endforeach
                            </select>
                            @error('packingUnit') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">POD / LR reference (optional)</span>
                            <input wire:model="podLrRef" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="LR-88213" />
                            @error('podLrRef') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">POD evidence (required)</span>
                            <input wire:model="podFile" type="file" accept="image/*,.pdf" capture="environment" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            <span class="text-xs" style="color: var(--text-muted);">Capture at the dock with the device camera or upload the signed POD. A typed LR reference alone cannot complete unloading.</span>
                            @error('podFile') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <button wire:click="completeUnloading({{ $g->id }})" wire:loading.attr="disabled" wire:target="completeUnloading({{ $g->id }})" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Complete unloading &amp; send to QC Check</button>
                    </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing in progress.</p>
        @endforelse
    </div>

    <section id="outward-loading" class="mt-8 rounded-2xl border p-4 sm:p-5" style="background: var(--surface-2); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Same Store Executive work area</div>
                <h2 class="font-semibold text-base mt-1" style="color: var(--text-primary);">Outward loading</h2>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Approved dispatches, loading window, document handover and exit readiness are managed here beside inward unloading.</p>
            </div>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--surface-1); color: var(--text-secondary);">{{ $outwardReadyToLoad->count() }} ready</span>
        </div>
        @error('loadingWindow')
            <div role="alert" class="rounded-lg border px-3 py-2 mb-3 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div>
        @enderror
        <div class="flex flex-col gap-2">
            @forelse ($outwardReadyToLoad as $g)
                <div class="rounded-xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · {{ $g->material }} · {{ $g->loading_dock }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-secondary);">EDD {{ $g->expected_delivery_date?->format('d M Y') ?? 'Not recorded' }} · Window {{ $g->loading_window_start?->format('d M Y, H:i') ?? 'Not scheduled' }} – {{ $g->loading_window_end?->format('H:i') ?? '—' }}</div>
                        </a>
                        @if (! $g->loading_started_at)
                            <button wire:click="startLoading({{ $g->id }})" wire:loading.attr="disabled" wire:target="startLoading({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 disabled:opacity-50 transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--brand);">Start loading</button>
                        @elseif ($loadingCompleting !== $g->id)
                            <button wire:click="openOutwardCompletion({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border shrink-0 transition hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--text-primary);">Open completion checklist</button>
                        @endif
                    </div>
                    @if ($g->loading_started_at)
                        <div class="mt-3 rounded-lg px-3 py-2 text-xs" style="background: var(--status-warning-bg); color: var(--status-warning);">Loading started {{ $g->loading_started_at->format('d M Y, H:i') }}. Complete the driver document handover checklist.</div>
                    @endif
                    @if ($loadingCompleting === $g->id)
                        <div class="mt-4 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-1);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">Driver document handover checklist</div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Tick every document physically handed to the driver before the vehicle is marked ready for exit.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                                @foreach (['invoice' => 'Invoice', 'bill' => 'Bill', 'pod' => 'POD documents', 'lr' => 'LR copy'] as $key => $label)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="documentsChecked.{{ $key }}" class="rounded" />
                                        <span style="color: var(--text-primary);">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <label class="flex items-start gap-2 text-sm mt-3 border-t pt-3" style="border-color: var(--border);">
                                <input type="checkbox" wire:model="documentsPackConfirmed" class="rounded mt-0.5" />
                                <span style="color: var(--text-primary);">I confirm the complete required document pack has been handed to the driver.</span>
                            </label>
                            @foreach (['invoice' => 'Invoice', 'bill' => 'Bill', 'pod' => 'POD documents', 'lr' => 'LR copy'] as $key => $label)
                                @error("documentsChecked.{$key}") <span class="block text-xs mt-1" style="color: var(--status-critical);">{{ $label }} must be checked.</span> @enderror
                            @endforeach
                            @error('documentsPackConfirmed') <span class="block text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            <div class="flex gap-2 mt-3">
                                <button wire:click="completeLoading({{ $g->id }})" wire:loading.attr="disabled" wire:target="completeLoading({{ $g->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Complete loading · ready for exit</button>
                                <button wire:click="$set('loadingCompleting', null)" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm py-2" style="color: var(--text-muted);">No outward dispatch is waiting for loading.</p>
            @endforelse
        </div>
        <h3 class="font-semibold text-sm mt-6 mb-2" style="color: var(--text-primary);">Outward loading history</h3>
        <div class="flex flex-col gap-2">
            @forelse ($outwardHistory as $g)
                <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="block rounded-lg border p-3 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between gap-3"><span class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</span><span class="text-xs" style="color: var(--status-good);">Loaded</span></div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name }} · {{ $g->loading_dock ?? 'Dispatch bay pending' }} · Loaded {{ $g->loaded_at?->format('d M, H:i') }}</div>
                </a>
            @empty
                <p class="text-sm py-2" style="color: var(--text-muted);">No completed outward loading records yet.</p>
            @endforelse
        </div>
    </section>

    <h2 class="font-semibold text-sm mb-2 mt-6" style="color: var(--text-primary);">History</h2>
    <div class="flex flex-col gap-2">
        @forelse ($history as $r)
            <a href="{{ $r->gateEntry ? route('gate-entries.show', $r->gateEntry) : '#' }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</div>
                    {{-- Confirmed live: this only ever checked completed_at, so an
                         entry that had only been allotted (started_at still null)
                         showed as "In progress" with 0 boxes -- box_count and
                         started_at both come from startUnloading(), not allot(). --}}
                    <span class="text-xs" style="color: var(--text-muted);">{{ $r->completed_at ? 'Completed' : ($r->started_at ? 'In progress' : 'Allotted, not started') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $r->gateEntry?->vendor_name }} · {{ $r->started_at ? $r->box_count.' '.($r->packing_unit ?? 'Box') : 'Not unloaded yet' }} · {{ $r->staging_area }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Allotted {{ $r->allotted_at?->format('d M, H:i') }}{{ $r->started_at ? ' · Started '.$r->started_at->format('d M, H:i') : '' }}{{ $r->completed_at ? ' · Completed '.$r->completed_at->format('d M, H:i') : '' }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No unloading records yet.</div>
        @endforelse
    </div>
</div>
