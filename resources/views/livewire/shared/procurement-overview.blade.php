<?php

use App\Models\GateEntry;
use App\Models\ValidationIssue;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Confirmed live: the Procurement Reviewer demo login had no way to see
// gate-level issues or closures at all -- Validation Issues is a Store
// Manager action screen (403 for anyone else), and there was no
// Procurement-facing view of any kind. Procurement's access is governed by
// the separate Tan90 role system (User::tan90Profile()), not the 7-role
// enum this app's other screens gate on, so this is a small read-only
// screen rather than opening up an actionable Store Manager screen to a
// different audience.
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $profile = auth()->user()->tan90Profile()->with('role')->first();

        abort_unless($profile?->role?->code === 'ROLE-PROCUREMENT', 403);
    }

    public function with(): array
    {
        return [
            'openIssues' => ValidationIssue::with('gateEntry')->where('status', 'open')->orderByDesc('created_at')->get(),
            'recentlyClosed' => GateEntry::with('grnRecord')->where('status', 'closed')->orderByDesc('updated_at')->limit(15)->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Procurement</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Issues &amp; Closures</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Read-only view — open gate validation issues and recently closed deliveries across all vendors.</p>
    </section>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Open issues</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($openIssues as $issue)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: {{ $issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)') }}; color: {{ $issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)') }};">{{ $issue->severity }}</span>
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $issue->title }}</span>
                </div>
                <p class="text-xs" style="color: var(--text-secondary);">{{ $issue->description }}</p>
                <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $issue->gateEntry?->gate_no }} · {{ $issue->gateEntry?->vendor_name ?? 'Unknown vendor' }} · {{ $issue->created_at->format('d M, H:i') }}</p>
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">No open issues.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Recently closed</h2>
    <div class="flex flex-col gap-2">
        @forelse ($recentlyClosed as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->po_number }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $gate->updated_at->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · bin {{ $gate->grnRecord?->suggested_bin ?? '—' }}</div>
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">Nothing closed yet.</div>
        @endforelse
    </div>
</div>
