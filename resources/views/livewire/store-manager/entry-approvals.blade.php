<?php

use App\Models\GateEntry;
use App\Models\UnloadingPackingUnit;
use App\Services\AuditLogger;
use App\Services\DockAssignmentService;
use App\Support\GateStatusLabels;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// New gate between Guard's save and the next stage: previously a clean
// inward entry auto-flipped straight to "validated" with no human ever
// looking at it, and outward entries had no workflow at all beyond Guard's
// save. Both journeys now require Store Manager to explicitly approve --
// see GateValidationService/bill-scan.blade.php, which always leaves a
// fresh inward or outward entry at pending_validation.
new #[Layout('layouts.app')] class extends Component
{
    public ?int $assigningOutward = null;
    public string $outwardDock = '';
    public string $loadingWindowStart = '';
    public string $loadingWindowEnd = '';
    public string $expectedDeliveryDate = '';
    public ?int $assigningInward = null;
    public string $inwardDock = '';
    public ?int $changingDock = null;
    public string $changeDockValue = '';
    public string $newPackingUnit = '';

    public array $outwardDocks = ['Dispatch Bay 1', 'Dispatch Bay 2', 'Dispatch Bay 3', 'Dispatch Bay 4'];
    public array $inwardDocks = ['Dock 1', 'Dock 2', 'Dock 3', 'Dock 4'];

    public function addPackingUnit(): void
    {
        $this->validate(['newPackingUnit' => ['required', 'string', 'max:40']]);
        $name = trim(ucwords(mb_strtolower($this->newPackingUnit)));

        if (UnloadingPackingUnit::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            $this->addError('newPackingUnit', 'That packing unit already exists.');

            return;
        }

        UnloadingPackingUnit::create([
            'name' => $name,
            'active' => true,
            'sort_order' => (int) UnloadingPackingUnit::max('sort_order') + 1,
            'created_by' => auth()->id(),
        ]);
        AuditLogger::log('Added inward packing unit', $name);
        $this->reset('newPackingUnit');
        session()->flash('success', "{$name} is now available on the Unloading Desk.");
    }

    public function togglePackingUnit(int $unitId): void
    {
        $unit = UnloadingPackingUnit::findOrFail($unitId);
        if ($unit->active && UnloadingPackingUnit::where('active', true)->count() <= 1) {
            $this->addError('packingUnits', 'At least one packing unit must remain active.');

            return;
        }

        $unit->update(['active' => ! $unit->active]);
        AuditLogger::log($unit->active ? 'Enabled inward packing unit' : 'Disabled inward packing unit', $unit->name, $unit);
        $state = $unit->active ? 'enabled' : 'disabled';
        session()->flash('success', "{$unit->name} {$state} for future unloading entries.");
    }

    private function occupiedOutwardDocks(?int $exceptGateId = null): array
    {
        return GateEntry::whereIn('status', ['dock_assigned', 'loaded'])->where('entry_type', 'outward')
            ->when($exceptGateId, fn ($q) => $q->where('id', '!=', $exceptGateId))
            ->pluck('loading_dock')->filter()->all();
    }

    private function availableOutwardDocks(?int $exceptGateId = null): array
    {
        return array_values(array_diff($this->outwardDocks, $this->occupiedOutwardDocks($exceptGateId)));
    }

    private function occupiedInwardDocks(?int $exceptGateId = null): array
    {
        return GateEntry::whereIn('status', ['dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted'])
            ->where('entry_type', 'inward')
            ->when($exceptGateId, fn ($q) => $q->where('id', '!=', $exceptGateId))
            ->pluck('loading_dock')
            ->filter()
            ->all();
    }

    private function availableInwardDocks(?int $exceptGateId = null): array
    {
        return array_values(array_diff($this->inwardDocks, $this->occupiedInwardDocks($exceptGateId)));
    }

    public function approve(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'pending_validation' || $gate->entry_type !== 'inward') {
            return;
        }

        if ($gate->hasBlockingOpenIssues()) {
            $this->addError('blocked', "{$gate->gate_no} still has an open hard-fail/red-flag issue — resolve it on Validation Issues first.");

            return;
        }

        $dock = app(DockAssignmentService::class)->choose($gate, $this->inwardDocks);
        if (! $dock) {
            $this->addError('blocked', "No inward dock is available for {$gate->gate_no}. The entry stays pending until a dock is released.");

            return;
        }

        $gate->update([
            'status' => 'dock_assigned',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'loading_dock' => $dock,
            'dock_assigned_at' => now(),
        ]);

        AuditLogger::log('Entry approved & dock auto-assigned', "{$gate->gate_no} approved by ".auth()->user()->name." · {$dock} (round robin)", $gate);
        session()->flash('success', "{$gate->gate_no} approved and auto-assigned to {$dock}. Store Executive can start unloading.");
    }

    public function openInwardDock(int $gateId): void
    {
        $this->assigningInward = $gateId;
        $this->inwardDock = $this->availableInwardDocks()[0] ?? '';
    }

    public function cancelInwardDock(): void
    {
        $this->reset(['assigningInward', 'inwardDock']);
    }

    public function assignInwardDock(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);
        if ($gate->entry_type !== 'inward' || $gate->status !== 'validated' || $gate->hasBlockingOpenIssues()) {
            $this->cancelInwardDock();

            return;
        }

        $this->validate(['inwardDock' => ['required', 'string', Rule::in($this->availableInwardDocks())]]);
        $gate->update([
            'status' => 'dock_assigned',
            'loading_dock' => $this->inwardDock,
            'dock_assigned_at' => now(),
        ]);

        AuditLogger::log('Assigned to inward unloading dock', "{$gate->gate_no} · {$this->inwardDock}", $gate);
        session()->flash('success', "{$gate->gate_no} assigned to {$this->inwardDock}. Store Executive can now count and unload.");
        $this->cancelInwardDock();
    }

    public function openChangeDock(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);
        if (! in_array($gate->status, ['dock_assigned', 'allotted', 'unloading'], true)) {
            return;
        }

        $this->changingDock = $gateId;
        $this->changeDockValue = (string) $gate->loading_dock;
    }

    public function cancelChangeDock(): void
    {
        $this->reset(['changingDock', 'changeDockValue']);
    }

    public function changeDock(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);
        $docks = $gate->entry_type === 'outward' ? $this->outwardDocks : $this->inwardDocks;
        $available = app(DockAssignmentService::class)->available($gate, $docks);
        $allowed = array_values(array_unique([...$available, (string) $gate->loading_dock]));

        $this->validate(['changeDockValue' => ['required', 'string', Rule::in($allowed)]]);
        $oldDock = $gate->loading_dock;
        $gate->update(['loading_dock' => $this->changeDockValue, 'dock_assigned_at' => now()]);

        AuditLogger::log('Manager changed assigned dock', "{$gate->gate_no} · {$oldDock} → {$this->changeDockValue}", $gate);
        session()->flash('success', "{$gate->gate_no} dock updated to {$this->changeDockValue}. The next owner can see the change immediately.");
        $this->cancelChangeDock();
    }

    public function openOutwardApproval(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);
        $this->assigningOutward = $gateId;
        $this->outwardDock = app(DockAssignmentService::class)->choose($gate, $this->outwardDocks) ?? '';
        $this->loadingWindowStart = '';
        $this->loadingWindowEnd = '';
        $this->expectedDeliveryDate = '';
    }

    public function cancelOutwardApproval(): void
    {
        $this->reset(['assigningOutward', 'outwardDock', 'loadingWindowStart', 'loadingWindowEnd', 'expectedDeliveryDate']);
    }

        // Store Manager owns approval and dock assignment for outward.
    public function approveOutward(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'pending_validation' || $gate->entry_type !== 'outward') {
            $this->cancelOutwardApproval();

            return;
        }

        if ($gate->hasBlockingOpenIssues()) {
            $this->addError('blocked', "{$gate->gate_no} still has an open hard-fail/red-flag issue.");

            return;
        }

        $this->validate([
            // These are planning fields. Do not make approval depend on date
            // parsing or a calendar-window comparison.
            'loadingWindowStart' => ['nullable', 'string', 'max:50'],
            'loadingWindowEnd' => ['nullable', 'string', 'max:50'],
            'expectedDeliveryDate' => ['nullable', 'string', 'max:50'],
        ]);

        // Window and EDD are operational planning hints. Do not block a real
        // vehicle because a date was missed or left blank; preserve supplied
        // values and provide safe defaults for the downstream screens.
        $windowStart = $this->safePlanningDate($this->loadingWindowStart, now());
        $windowEnd = $this->safePlanningDate($this->loadingWindowEnd, $windowStart->copy()->addHours(8));
        if ($windowEnd->lt($windowStart)) {
            $windowEnd = $windowStart->copy()->addHours(8);
        }

        $dock = app(DockAssignmentService::class)->choose($gate, $this->outwardDocks, $this->outwardDock ?: null);
        if (! $dock) {
            $this->addError('blocked', "No outward dock is available for {$gate->gate_no}. The entry stays pending until a dock is released.");

            return;
        }

        $gate->update([
            'status' => 'dock_assigned',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'loading_dock' => $dock,
            'dock_assigned_at' => now(),
            'loading_window_start' => $windowStart,
            'loading_window_end' => $windowEnd,
            'expected_delivery_date' => $this->safePlanningDate($this->expectedDeliveryDate, today())->toDateString(),
        ]);

        AuditLogger::log('Outward entry approved & dock assigned', "{$gate->gate_no} · {$dock} · EDD ".$this->safePlanningDate($this->expectedDeliveryDate, today())->format('d M Y'), $gate);
        session()->flash('success', "{$gate->gate_no} approved and assigned to {$dock}. Store Executive can now start loading; planning dates are informational only.");

        $this->cancelOutwardApproval();
    }

    private function safePlanningDate(?string $value, \Illuminate\Support\Carbon $fallback): \Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return $fallback->copy();
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    public function with(): array
    {
        // Confirmed live: this sorted oldest-first with no cap. A handful of
        // never-resolved test entries from weeks earlier sat permanently at
        // the top, so a newly-saved entry was buried at the bottom of the
        // list instead of being the first thing Store Manager saw.
        return [
            'pendingInward' => GateEntry::where('entry_type', 'inward')
                ->where('status', 'pending_validation')
                ->with('validationIssues')
                ->orderByDesc('created_at')
                ->get(),
            'inwardAwaitingDock' => GateEntry::where('entry_type', 'inward')
                ->where('status', 'validated')
                ->with('validationIssues')
                ->orderByDesc('approved_at')
                ->get(),
            'pendingOutward' => GateEntry::where('entry_type', 'outward')
                ->where('status', 'pending_validation')
                ->with('validationIssues')
                ->orderByDesc('created_at')
                ->get(),
            'availableOutwardDocks' => $this->availableOutwardDocks(),
            'availableInwardDocks' => $this->availableInwardDocks(),
            'recentlyApproved' => GateEntry::whereIn('entry_type', ['inward', 'outward'])
                ->whereNotNull('approved_at')
                ->with('approvedBy')
                ->orderByDesc('approved_at')
                ->limit(8)
                ->get(),
            'unloadingDockProgress' => GateEntry::where('entry_type', 'inward')
                ->whereIn('status', ['dock_assigned', 'allotted', 'unloading', 'grn'])
                ->with('unloadingRecord')
                ->orderByDesc('dock_assigned_at')
                ->limit(8)
                ->get(),
            'outwardLoadingProgress' => GateEntry::where('entry_type', 'outward')
                ->whereIn('status', ['dock_assigned', 'loaded'])
                ->orderByDesc('dock_assigned_at')
                ->limit(8)
                ->get(),
            'packingUnits' => UnloadingPackingUnit::orderBy('sort_order')->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Entry Approvals</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every inward and outward gate entry waits here until you approve it.</p>
    </section>

    @error('blocked')
        <div class="rounded-lg border p-3 mb-4 text-sm" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div>
    @enderror
    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-lg border p-3 mb-4 text-sm font-medium" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Inward — awaiting approval</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pendingInward as $gate)
            @php $blocked = $gate->hasBlockingOpenIssues(); @endphp
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->po_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->vendor_name }} · {{ $gate->material }} · {{ $gate->vehicle_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Arrived {{ $gate->created_at->format('d M, H:i') }}</div>
                    </a>
                    @if ($blocked)
                        <a href="{{ route('validation.issues') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium border shrink-0" style="border-color: var(--status-critical); color: var(--status-critical);">Resolve issues first →</a>
                    @else
                        <button wire:click="approve({{ $gate->id }})" wire:loading.attr="disabled" wire:target="approve({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Approve entry</button>
                    @endif
                </div>
                @if ($gate->validationIssues->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5 mt-3">
                        @foreach ($gate->validationIssues as $issue)
                            <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: {{ $issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)') }}; color: {{ $issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)') }};">{{ $issue->title }} · {{ $issue->status }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">Nothing waiting for approval.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Inward — legacy entries awaiting dock assignment</h2>
    <p class="text-xs mb-3" style="color: var(--text-muted);">New approvals receive a dock automatically in round-robin order. This section is only for older entries created before automatic assignment.</p>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($inwardAwaitingDock as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->po_number }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · {{ $gate->material }} · Approved {{ $gate->approved_at?->format('d M, H:i') }}</div>
                    </div>
                    @if ($assigningInward !== $gate->id)
                        <button wire:click="openInwardDock({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);" @if (empty($availableInwardDocks)) disabled @endif>Assign inward dock</button>
                    @endif
                </div>
                @if ($assigningInward === $gate->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Unloading dock</span>
                            <select wire:model="inwardDock" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                <option value="">Select dock…</option>
                                @foreach ($availableInwardDocks as $dock)<option value="{{ $dock }}">{{ $dock }}</option>@endforeach
                            </select>
                            @error('inwardDock') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="flex gap-2 sm:mt-6">
                            <button wire:click="assignInwardDock({{ $gate->id }})" wire:loading.attr="disabled" wire:target="assignInwardDock({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm dock</button>
                            <button wire:click="cancelInwardDock" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-5" style="color: var(--text-muted);">No approved inward entries are waiting for a dock.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Outward — awaiting approval &amp; dock</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pendingOutward as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->vendor_name }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->material }} · {{ $gate->vehicle_number }} · Arrived {{ $gate->created_at->format('d M, H:i') }}</div>
                    </a>
                    @if ($assigningOutward !== $gate->id)
                        <button wire:click="openOutwardApproval({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Approve &amp; assign dock</button>
                    @endif
                </div>

                @if ($assigningOutward === $gate->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Dispatch bay</span>
                            <select wire:model="outwardDock" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                <option value="">Select…</option>
                                @foreach ($availableOutwardDocks as $d) <option value="{{ $d }}">{{ $d }}</option> @endforeach
                            </select>
                            @error('outwardDock') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Expected delivery date</span>
                            <input wire:model="expectedDeliveryDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('expectedDeliveryDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Loading window start</span>
                            <input wire:model="loadingWindowStart" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('loadingWindowStart') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Loading window end</span>
                            <input wire:model="loadingWindowEnd" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('loadingWindowEnd') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="sm:col-span-2 flex gap-2">
                            <button wire:click="approveOutward({{ $gate->id }})" wire:loading.attr="disabled" wire:target="approveOutward({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm approval</button>
                            <button wire:click="cancelOutwardApproval" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">Nothing waiting for approval.</div>
    @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Outward loading — read-only progress</h2>
    <p class="text-xs mb-3" style="color: var(--text-muted);">Store Executive owns loading. Start and completion timestamps are recorded automatically. Manager can change the assigned bay if required.</p>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($outwardLoadingProgress as $gate)
            <div wire:key="manager-outward-progress-{{ $gate->id }}" class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->vendor_name }}</div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold" style="color: {{ $gate->loaded_at ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $gate->loaded_at ? 'Loading complete' : 'Loading not started' }}</span>
                        @if ($changingDock !== $gate->id)
                            <button type="button" wire:click="openChangeDock({{ $gate->id }})" class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold" style="border-color: var(--border); color: var(--brand);">Change dock</button>
                        @endif
                    </div>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->material }} · {{ $gate->loading_dock }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Start time: {{ $gate->loading_started_at?->format('d M Y, H:i') ?? 'Pending' }} · End time: {{ $gate->loaded_at?->format('d M Y, H:i') ?? 'Pending' }}</div>
                @if ($changingDock === $gate->id)
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <label class="flex flex-col gap-1 text-xs font-medium" style="color: var(--text-primary);">Assigned bay
                            <select wire:model="changeDockValue" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                                @foreach (array_unique([...$availableOutwardDocks, $gate->loading_dock]) as $dock)<option value="{{ $dock }}">{{ $dock }}</option>@endforeach
                            </select>
                        </label>
                        <button type="button" wire:click="changeDock({{ $gate->id }})" class="rounded-lg px-3 py-2 text-xs font-semibold text-white" style="background: var(--brand);">Save bay</button>
                        <button type="button" wire:click="cancelChangeDock" class="rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                    </div>
                    @error('changeDockValue') <span class="text-xs mt-1 block" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-5" style="color: var(--text-muted);">No outward loading is currently assigned or in progress.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2 mt-6" style="color: var(--text-primary);">Inward unloading dock — live progress</h2>
    <p class="text-xs mb-3" style="color: var(--text-muted);">Dock is assigned automatically. Store Manager can change it here if vehicle and dock size do not match.</p>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($unloadingDockProgress as $gate)
            <div wire:key="manager-inward-progress-{{ $gate->id }}" class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="text-sm font-medium hover:opacity-80" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->po_number }}</a>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold" style="color: var(--status-warning);">{{ GateStatusLabels::label($gate->status) }}</span>
                        @if ($changingDock !== $gate->id)
                            <button type="button" wire:click="openChangeDock({{ $gate->id }})" class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold" style="border-color: var(--border); color: var(--brand);">Change dock</button>
                        @endif
                    </div>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · {{ $gate->loading_dock ?? 'Dock pending' }} · {{ $gate->unloadingRecord?->manpower_count ?? '—' }} crew</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Physical count: {{ $gate->unloadingRecord?->box_count ?: 'Pending' }} {{ $gate->unloadingRecord?->packing_unit ?? '' }} · POD: {{ $gate->unloadingRecord?->pod_document_path || $gate->unloadingRecord?->pod_lr_ref ? 'Captured' : 'Pending' }}</div>
                @if ($changingDock === $gate->id)
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <label class="flex flex-col gap-1 text-xs font-medium" style="color: var(--text-primary);">Assigned dock
                            <select wire:model="changeDockValue" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                                @foreach (array_unique([...$availableInwardDocks, $gate->loading_dock]) as $dock)<option value="{{ $dock }}">{{ $dock }}</option>@endforeach
                            </select>
                        </label>
                        <button type="button" wire:click="changeDock({{ $gate->id }})" class="rounded-lg px-3 py-2 text-xs font-semibold text-white" style="background: var(--brand);">Save dock</button>
                        <button type="button" wire:click="cancelChangeDock" class="rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                    </div>
                    @error('changeDockValue') <span class="text-xs mt-1 block" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">No unloading currently on a dock.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Recently approved</h2>
    <div class="flex flex-col gap-2">
        @forelse ($recentlyApproved as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="text-sm font-medium hover:opacity-80" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ ucfirst($gate->entry_type) }}</a>
                    <span class="text-xs" style="color: var(--text-muted);">{{ GateStatusLabels::label($gate->status) }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · Approved by {{ $gate->approvedBy?->name ?? '—' }} on {{ $gate->approved_at?->format('d M, H:i') }}</div>
                @if ($gate->entry_type === 'inward' && $gate->status === 'validated')
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Next owner: Store Manager — assign dock</span>
                        <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-primary);">View entry details</a>
                    </div>
                @elseif ($gate->entry_type === 'outward' && $gate->status === 'dock_assigned')
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Next owner: Store Executive — complete outward loading</span>
                        <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-primary);">View entry details</a>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No approvals recorded yet.</div>
        @endforelse
    </div>

    <details class="rounded-xl border p-4 mt-6" style="background: var(--surface-3); border-color: var(--border);">
        <summary class="cursor-pointer text-sm font-semibold" style="color: var(--text-primary);">Configure inward packing units</summary>
        <p class="text-xs mt-2" style="color: var(--text-secondary);">Active units appear in the Store Executive Unloading Desk. Disabling a unit only affects future entries; saved history is preserved.</p>
        @error('packingUnits') <div role="alert" class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</div> @enderror
        <div class="flex flex-col gap-2 mt-3">
            @foreach ($packingUnits as $unit)
                <div class="flex items-center justify-between gap-3 border-t pt-2" style="border-color: var(--border);">
                    <span class="text-sm" style="color: var(--text-primary);">{{ $unit->name }}</span>
                    <button type="button" wire:click="togglePackingUnit({{ $unit->id }})" class="rounded-lg border px-3 py-2 text-xs font-semibold min-h-11" style="border-color: var(--border); color: {{ $unit->active ? 'var(--status-good)' : 'var(--text-muted)' }};">{{ $unit->active ? 'Active · disable' : 'Disabled · enable' }}</button>
                </div>
            @endforeach
        </div>
        <div class="flex flex-col sm:flex-row gap-2 mt-3">
            <input wire:model="newPackingUnit" class="rounded-lg border px-3 py-2 text-sm min-h-11" style="border-color: var(--border); background: var(--surface-1);" placeholder="Add a packing unit" aria-label="New packing unit" />
            <button type="button" wire:click="addPackingUnit" class="rounded-lg px-3 py-2 text-sm font-semibold text-white min-h-11" style="background: var(--brand);">Add packing unit</button>
        </div>
        @error('newPackingUnit') <span role="alert" class="text-xs mt-1 block" style="color: var(--status-critical);">{{ $message }}</span> @enderror
    </details>
</div>
