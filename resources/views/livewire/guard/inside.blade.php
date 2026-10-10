<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// One place for the Guard to let any vehicle / visitor OUT of the gate.
// Inward: records the exit time only (QC / GRN carry on as before).
// Outward: the existing exit (GateExitService) - only once loading is done.
// Visitor: the existing check-out.
new #[Layout('layouts.app')] class extends Component
{
    // Older inward passes never had an exit step; only recent ones are listed.
    private const INWARD_DAYS = 2;

    public function inwardOut(int $id): void
    {
        $gate = GateEntry::where('entry_type', 'inward')->whereNull('exited_at')->findOrFail($id);
        $gate->update(['exited_at' => now()]);
        AuditLogger::log('Inward vehicle exited premises', $gate->gate_no.' · '.$gate->vehicle_number, $gate);
        session()->flash('success', "Truck Out recorded for {$gate->gate_no} ({$gate->vehicle_number}).");
    }

    public function outwardOut(int $id): void
    {
        $gate = GateEntry::where('entry_type', 'outward')->findOrFail($id);
        if ($gate->status === 'rejected') {
            // Rejected at the gate: the empty truck simply leaves, no dispatch.
            $gate->update(['exited_at' => now()]);
            AuditLogger::log('Outward vehicle exited premises', $gate->gate_no.' · rejected, no dispatch', $gate);
            session()->flash('success', "Truck Out recorded for {$gate->gate_no}.");

            return;
        }
        try {
            $shipment = app(\App\Services\Outward\GateExitService::class)->confirm($gate, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('exit.'.$id, collect($e->errors())->flatten()->implode(' '));

            return;
        }
        session()->flash('success', "Truck Out confirmed for {$gate->gate_no}. Outward dispatch is complete.".($shipment ? " {$shipment->number}: stock has been deducted." : ''));
    }

    public function visitorOut(int $id): void
    {
        $gate = GateEntry::where('entry_type', 'visitor')->where('status', 'checked_in')->findOrFail($id);
        $gate->update(['status' => 'closed', 'visitor_checked_out_at' => now()]);
        AuditLogger::log('Visitor checked out', $gate->gate_no.' · '.$gate->driver_name, $gate);
        session()->flash('success', "Visitor checked out. Pass {$gate->gate_no} is closed.");
    }

    public function with(): array
    {
        return [
            'vehicles' => self::insideQuery()->with('contactUser')->latest('id')->get(),
        ];
    }

    public static function insideQuery()
    {
        return GateEntry::query()->where(function ($q) {
            $q->where(fn ($i) => $i->where('entry_type', 'inward')->whereNull('exited_at')->where('created_at', '>=', now()->subDays(self::INWARD_DAYS)->startOfDay()))
                ->orWhere(fn ($o) => $o->where('entry_type', 'outward')->whereNull('exited_at')->where('status', '!=', 'dispatched'))
                ->orWhere(fn ($v) => $v->where('entry_type', 'visitor')->where('status', 'checked_in'));
        });
    }

    public function stage(GateEntry $e): string
    {
        return match ($e->entry_type) {
            'outward' => match ($e->status) {
                'loaded' => 'Loaded - ready to leave',
                'rejected' => 'Rejected - can leave',
                'pending_validation' => 'Waiting for Store Manager approval',
                default => 'Loading not done yet ('.str_replace('_', ' ', $e->status).')',
            },
            'visitor' => 'Visitor inside',
            default => match ($e->status) {
                'pending_validation' => 'Waiting for Store Manager approval',
                'closed' => 'Material stored',
                default => 'Inside - '.str_replace('_', ' ', $e->status),
            },
        };
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Guard Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Vehicles Inside (Truck Out)</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every truck and visitor still inside the gate. Press Out when they leave.</p>
    </section>

    @if (session('success'))
        <div class="rounded-lg border px-4 py-3 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    @forelse ($vehicles as $v)
        <div wire:key="inside-{{ $v->id }}" class="rounded-2xl border p-4 flex flex-col sm:flex-row sm:items-center gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase px-2 py-0.5 rounded-full" style="background: var(--brand-bg); color: var(--brand);">{{ $v->entry_type }}</span>
                    <a href="{{ route('guard.entries.show', $v) }}" wire:navigate class="font-semibold underline" style="color: var(--text-primary);">{{ $v->gate_no }}</a>
                    <span class="text-sm" style="color: var(--text-primary);">{{ $v->vehicle_number ?: '—' }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    {{ $v->driver_name ?: '—' }} · {{ $v->vendor_name ?: '' }} @if ($v->contactUser) · Meeting {{ $v->contactUser->name }} @endif · In at {{ $v->created_at?->format('d M, h:i A') }}
                </div>
                <div class="text-xs mt-1 font-semibold" style="color: {{ in_array($v->status, ['loaded', 'rejected', 'checked_in'], true) || $v->entry_type === 'inward' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $this->stage($v) }}</div>
                @error('exit.'.$v->id) <div role="alert" class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
            </div>
            <div class="shrink-0">
                @if ($v->entry_type === 'inward')
                    <button wire:click="inwardOut({{ $v->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">Truck Out</button>
                @elseif ($v->entry_type === 'outward' && in_array($v->status, ['loaded', 'rejected'], true))
                    <button wire:click="outwardOut({{ $v->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">Truck Out</button>
                @elseif ($v->entry_type === 'visitor')
                    <button wire:click="visitorOut({{ $v->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">Check out</button>
                @else
                    <span class="text-xs" style="color: var(--text-muted);">Out opens after loading</span>
                @endif
            </div>
        </div>
    @empty
        <div class="rounded-2xl border p-6 text-sm text-center" style="background: var(--surface-3); border-color: var(--border); color: var(--text-muted);">No vehicles or visitors inside right now.</div>
    @endforelse
</div>
