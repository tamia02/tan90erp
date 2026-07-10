<?php

use App\Enums\Role;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const FAQS = [
        'guard' => [
            ['q' => 'What happens if I save a gate entry with an issue?', 'a' => 'It still saves — the entry is flagged pending_validation and sent to Store Manager\'s Validation Issues queue.'],
            ['q' => 'What starts the 12-hour SLA timer?', 'a' => 'Saving the gate entry — you\'ll be notified automatically if it breaches.'],
        ],
        'vendor' => [
            ['q' => 'Why can\'t I submit without a PO number?', 'a' => 'The gate can\'t match your delivery without one — Continue stays disabled until it\'s filled in.'],
            ['q' => 'Who resolves issues raised against my delivery?', 'a' => 'The store team, from the Validation Issues screen — you can only view them here.'],
        ],
        'storeExec' => [
            ['q' => 'What happens after I complete unloading?', 'a' => 'The gate entry moves to QC Check automatically.'],
        ],
        'qc' => [
            ['q' => 'Does QC Check post to stock?', 'a' => 'No — QC only records the split. GRN Check (Store Manager) is the only step that posts to the ledger.'],
        ],
        'storeManager' => [
            ['q' => 'Can I post a GRN without a QC Check first?', 'a' => 'No — GRN Check reads the QC result for that gate entry; it must exist first.'],
        ],
        'finance' => [
            ['q' => 'What sets the final payable amount?', 'a' => 'Accepted quantity × rate per unit, minus defective/rejected/missing deductions — computed automatically at GRN posting.'],
        ],
        'admin' => [
            ['q' => 'How do I add a new team member?', 'a' => 'Users → Add user. Every account is admin-provisioned; there\'s no self-registration.'],
        ],
    ];

    public function with(): array
    {
        return ['faqs' => self::FAQS[auth()->user()->role->value] ?? []];
    }
}; ?>

<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Help &amp; Support</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Answers for the {{ auth()->user()->role->label() }} module.</p>

    <div class="flex flex-col gap-2 mb-6">
        @foreach ($faqs as $faq)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $faq['q'] }}</div>
                <div class="text-sm mt-1" style="color: var(--text-secondary);">{{ $faq['a'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-2); border-color: var(--border);">
        <div class="text-sm font-medium" style="color: var(--text-primary);">Still need help?</div>
        <div class="text-sm mt-1" style="color: var(--text-secondary);">Contact support at +91 90000 00000.</div>
    </div>
</div>
