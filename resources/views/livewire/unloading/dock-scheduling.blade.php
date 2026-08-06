<?php

use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Vendors already give advance notice of what's coming (vendor/submissions.blade.php).
// This is the missing other half: once a vendor also gives an expected
// arrival time, someone needs to assign a physical dock and a scheduled
// slot before the truck actually shows up at Guard's gate.
new #[Layout('layouts.app')] class extends Component
{
    public ?int $scheduling = null;
    public string $dockNumber = '';
    public string $dockScheduledAt = '';

    public function schedule(int $id): void
    {
        $submission = VendorSubmission::findOrFail($id);
        $this->scheduling = $submission->id;
        $this->dockNumber = $submission->dock_number ?? '';
        $this->dockScheduledAt = $submission->dock_scheduled_at?->format('Y-m-d\TH:i') ?? $submission->expected_arrival_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function saveDock(int $id): void
    {
        $this->validate([
            'dockNumber' => ['required', 'string', 'max:50'],
            'dockScheduledAt' => ['required', 'date'],
        ]);

        $submission = VendorSubmission::findOrFail($id);
        $submission->update(['dock_number' => $this->dockNumber, 'dock_scheduled_at' => $this->dockScheduledAt]);

        AuditLogger::log('Dock scheduled', "{$submission->vendor_name} · {$submission->po_number} · Dock {$this->dockNumber}", $submission);

        $this->reset(['scheduling', 'dockNumber', 'dockScheduledAt']);
    }

    public function clearDock(int $id): void
    {
        $submission = VendorSubmission::findOrFail($id);
        $submission->update(['dock_number' => null, 'dock_scheduled_at' => null]);

        AuditLogger::log('Dock schedule cleared', "{$submission->vendor_name} · {$submission->po_number}", $submission);
    }

    public function with(): array
    {
        return [
            'submissions' => VendorSubmission::whereNotNull('expected_arrival_at')->orderBy('expected_arrival_at')->get(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Dock Scheduling</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every vendor's advance shipping notice (ASN) with an expected arrival time, and its assigned dock slot.</p>

    <div class="flex flex-col gap-3">
        @forelse ($submissions as $sub)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $sub->vendor_name }} · {{ $sub->po_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            ETA {{ $sub->expected_arrival_at->format('d M Y, H:i') }}
                            @if ($sub->vehicle_number) · {{ $sub->vehicle_number }} @endif
                            @if ($sub->material) · {{ $sub->material }} @endif
                        </div>
                    </div>
                    @if ($sub->dock_number)
                        <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--status-good);">Dock {{ $sub->dock_number }} · {{ $sub->dock_scheduled_at?->format('d M, H:i') }}</span>
                    @else
                        <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--status-warning);">No dock assigned</span>
                    @endif
                </div>

                @if ($scheduling === $sub->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Dock number</span>
                            <input wire:model="dockNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Dock 3" />
                            @error('dockNumber') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Scheduled slot</span>
                            <input wire:model="dockScheduledAt" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('dockScheduledAt') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </label>
                    </div>
                @endif

                <div class="flex gap-2 mt-3">
                    @if ($scheduling !== $sub->id)
                        <button wire:click="schedule({{ $sub->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">{{ $sub->dock_number ? 'Reschedule' : 'Assign dock' }}</button>
                    @else
                        <button wire:click="saveDock({{ $sub->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Save</button>
                    @endif
                    @if ($sub->dock_number)
                        <button wire:click="clearDock({{ $sub->id }})" wire:confirm="Clear this dock assignment?" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Clear</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No vendor has given an expected arrival time yet.</div>
        @endforelse
    </div>
</div>
