<?php

use App\Models\LedgerEntry;
use App\Models\ValidationIssue;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $raising = false;
    public string $issueType = 'not_mapped';
    public string $sku = '';
    public string $title = '';
    public string $description = '';

    public array $issueTypes = [
        'not_mapped' => 'Not Mapped (shelf/rack/bin not assigned)',
        'not_found_across_vendors' => 'Not Found Across All Vendors',
    ];

    public function raiseIssue(): void
    {
        $this->validate([
            'sku' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        $issue = ValidationIssue::create([
            'code' => strtoupper(str_replace('_', '-', $this->issueType)),
            'type' => $this->issueType,
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => 'warning',
            'status' => 'open',
        ]);

        AuditLogger::log('Issue raised · '.$this->issueTypes[$this->issueType], "{$issue->sku} · {$issue->title}", $issue);

        $this->reset(['sku', 'title', 'description', 'raising']);
        $this->issueType = 'not_mapped';
    }

    public function updateStatus(int $id, string $status): void
    {
        $issue = ValidationIssue::findOrFail($id);
        $issue->update(['status' => $status]);

        AuditLogger::log("Issue {$status}", $issue->id.($issue->owner ? " · owner {$issue->owner}" : ''), $issue);

        if ($status === 'escalated') {
            AuditLogger::log('Issue escalated to Finance Controller', "{$issue->title} · {$issue->sku}", $issue);
        }

        $gate = $issue->gateEntry;
        if ($gate && $gate->status === 'pending_validation' && ! $gate->hasBlockingOpenIssues()) {
            $gate->update(['status' => 'validated']);
        }
    }

    public function with(): array
    {
        $entries = LedgerEntry::all();

        $inventory = $entries
            ->groupBy('sku')
            ->map(fn ($rows, $sku) => [
                'sku' => $sku,
                'available' => $rows->where('bucket', 'available')->sum('qty'),
                'qcHold' => $rows->where('bucket', 'qcHold')->sum('qty'),
            ])
            ->values();

        return [
            'issues' => ValidationIssue::with('gateEntry')->orderByDesc('created_at')->get(),
            'inventory' => $inventory,
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between gap-3 mb-1">
        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Validation Issues</h1>
        <button wire:click="$toggle('raising')" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">{{ $raising ? 'Cancel' : 'Raise issue' }}</button>
    </div>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every issue raised at the gate, across all vendors and POs.</p>

    @if ($raising)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Issue type</span>
                <select wire:model="issueType" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($issueTypes as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">SKU</span>
                <input wire:model="sku" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('sku') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Title</span>
                <input wire:model="title" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('title') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <textarea wire:model="description" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                @error('description') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <button wire:click="raiseIssue" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Raise issue</button>
        </div>
    @endif

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Current inventory check</h2>
        @if ($inventory->isEmpty())
            <p class="text-sm py-2" style="color: var(--text-muted);">No stock posted yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                            <th class="py-2 font-medium">SKU</th>
                            <th class="py-2 font-medium">Available</th>
                            <th class="py-2 font-medium">QC Hold</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inventory as $row)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="py-2 font-medium" style="color: var(--text-primary);">{{ $row['sku'] }}</td>
                                <td class="py-2" style="color: var(--status-good);">{{ $row['available'] }}</td>
                                <td class="py-2" style="color: var(--status-warning);">{{ $row['qcHold'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($issues as $issue)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center gap-2 mb-2 flex-wrap">
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: {{ $issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)') }}; color: {{ $issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)') }};">{{ $issue->severity }}</span>
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary);">{{ $issue->status }}</span>
                    @if ($issue->type)
                        <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--brand);">{{ $issueTypes[$issue->type] ?? $issue->type }}</span>
                    @endif
                </div>
                <h3 class="font-medium text-sm" style="color: var(--text-primary);">{{ $issue->title }}</h3>
                <p class="text-sm mt-0.5" style="color: var(--text-secondary);">{{ $issue->description }}</p>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    @if ($issue->gateEntry)
                        {{ $issue->gateEntry->po_number ? $issue->gateEntry->po_number.' · ' : '' }}{{ $issue->gateEntry->vendor_name ?? 'Unknown vendor' }} ·
                    @elseif ($issue->sku)
                        SKU {{ $issue->sku }} ·
                    @endif
                    Raised {{ $issue->created_at->format('d M Y, H:i') }}
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
