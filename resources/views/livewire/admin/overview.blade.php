<?php

use App\Models\GateEntry;
use App\Models\User;
use App\Models\ValidationIssue;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'userCount' => User::count(),
            'openIssues' => ValidationIssue::where('status', 'open')->count(),
            'gatesInFlight' => GateEntry::where('status', '!=', 'closed')->count(),
            'gatesClosed' => GateEntry::where('status', 'closed')->count(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Admin Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Overview</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Welcome, {{ auth()->user()->name }}.</p>
    </section>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Team Members</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $userCount }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Open Issues</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $openIssues }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Gates In Flight</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $gatesInFlight }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Gates Closed</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $gatesClosed }}</div>
        </div>
    </div>
</div>
