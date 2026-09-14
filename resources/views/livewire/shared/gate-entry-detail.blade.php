<?php

use App\Enums\Role;
use App\Models\GateEntry;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Client-reported gap: every downstream screen (Unloading Desk, QC, GRN,
// Finance) could act on a gate entry via its list cards, but had no way to
// open the full record - only Guard's own guard.entries.show did that, and
// that route is guard-only. This is the shared equivalent every other role
// links to, with QC/GRN/Finance sections gated by role: Guard sees only its
// own submitted fields + validation issues (the same restriction applied to
// guard.entry-detail after the client asked for it removed from Guard's
// login); every other internal-staff role sees the full downstream chain,
// matching this app's existing "internal staff are cross-role trusted"
// pattern (see shared.activity-detail's own comment); Vendor is blocked
// from opening another vendor's entry, same ownership check as there.
new #[Layout('layouts.app')] class extends Component
{
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'gate_entry_id'];

    public GateEntry $entry;

    public function mount(GateEntry $entry): void
    {
        $user = auth()->user();

        if ($user->role === Role::Vendor && $entry->vendor_name !== $user->name) {
            abort(403);
        }

        $this->entry = $entry;
    }

    public function with(): array
    {
        $user = auth()->user();
        $showDownstream = $user->role !== Role::Guard;

        $this->entry->loadMissing(array_filter([
            'validationIssues',
            $showDownstream ? 'qcResult' : null,
            $showDownstream ? 'grnRecord' : null,
            $showDownstream ? 'financeRecord' : null,
        ]));

        return [
            'fields' => $this->describe($this->entry),
            'issues' => $this->entry->validationIssues,
            'qcFields' => $showDownstream && $this->entry->qcResult ? $this->describe($this->entry->qcResult) : [],
            'grnFields' => $showDownstream && $this->entry->grnRecord ? $this->describe($this->entry->grnRecord) : [],
            'financeFields' => $showDownstream && $this->entry->financeRecord ? $this->describe($this->entry->financeRecord) : [],
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function describe(Model $subject): array
    {
        return collect($subject->toArray())
            ->except(self::HIDDEN_FIELDS)
            ->map(fn ($value, $key) => [
                'label' => str($key)->replace('_', ' ')->headline()->toString(),
                'value' => $this->formatValue($value),
            ])
            ->values()
            ->all();
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return empty($value) ? '—' : json_encode($value);
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value)) {
            return \Illuminate\Support\Carbon::parse($value)->format('d M Y, H:i');
        }

        return (string) $value;
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <a href="{{ url()->previous() }}" wire:navigate class="text-xs font-semibold inline-flex items-center gap-1" style="color: var(--brand);">
            <x-icon name="chevron-left" class="w-3.5 h-3.5" /> Back
        </a>
        <h1 class="text-2xl font-bold mt-3" style="color: var(--text-primary);">{{ $entry->gate_no }}</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Full gate entry form as submitted, plus every stage it has moved through since.</p>
    </section>

    <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Gate entry — submitted details</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
            @foreach ($fields as $f)
                <div class="text-sm">
                    <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                    <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($issues->isNotEmpty())
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Validation issues</h2>
            <div class="flex flex-col gap-2">
                @foreach ($issues as $issue)
                    <div class="rounded-lg border p-3 text-sm" style="border-color: var(--border); background: {{ $issue->status === 'open' ? 'var(--status-critical-bg)' : 'var(--surface-2)' }};">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium" style="color: var(--text-primary);">{{ $issue->title }}</span>
                            <span class="text-xs capitalize shrink-0" style="color: var(--text-muted);">{{ $issue->severity }} &bull; {{ $issue->status }}</span>
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $issue->description }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($qcFields)
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">QC result</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                @foreach ($qcFields as $f)
                    <div class="text-sm">
                        <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                        <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($grnFields)
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">GRN record</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                @foreach ($grnFields as $f)
                    <div class="text-sm">
                        <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                        <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($financeFields)
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Finance record</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                @foreach ($financeFields as $f)
                    <div class="text-sm">
                        <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                        <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
