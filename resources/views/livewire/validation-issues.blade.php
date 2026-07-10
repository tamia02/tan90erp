<?php

use App\Models\ValidationIssue;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function updateStatus(int $id, string $status): void
    {
        $issue = ValidationIssue::findOrFail($id);
        $issue->update(['status' => $status]);

        AuditLogger::log("Issue {$status}", $issue->id.($issue->owner ? " · owner {$issue->owner}" : ''));

        $gate = $issue->gateEntry;
        if ($gate && $gate->status === 'pending_validation' && ! $gate->hasBlockingOpenIssues()) {
            $gate->update(['status' => 'validated']);
        }
    }

    public function with(): array
    {
        return [
            'issues' => ValidationIssue::with('gateEntry')->orderByDesc('created_at')->get(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Validation Issues</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every issue raised at the gate, across all vendors and POs.</p>

    <div class="flex flex-col gap-2">
        @forelse ($issues as $issue)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center gap-2 mb-2 flex-wrap">
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: {{ $issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)') }}; color: {{ $issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)') }};">{{ $issue->severity }}</span>
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary);">{{ $issue->status }}</span>
                </div>
                <h3 class="font-medium text-sm" style="color: var(--text-primary);">{{ $issue->title }}</h3>
                <p class="text-sm mt-0.5" style="color: var(--text-secondary);">{{ $issue->description }}</p>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    {{ $issue->gateEntry?->po_number ? $issue->gateEntry->po_number.' · ' : '' }}{{ $issue->gateEntry?->vendor_name ?? 'Unknown vendor' }} · Raised {{ $issue->created_at->format('d M Y, H:i') }}
                </p>
                @if ($issue->status === 'open')
                    <div class="flex gap-2 mt-3">
                        <button wire:click="updateStatus({{ $issue->id }}, 'approved')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">Approve</button>
                        <button wire:click="updateStatus({{ $issue->id }}, 'resolved')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Resolve</button>
                        <button wire:click="updateStatus({{ $issue->id }}, 'escalated')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Escalate</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No issues raised.</div>
        @endforelse
    </div>
</div>
