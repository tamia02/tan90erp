<?php

use App\Enums\Role;
use App\Models\AuditLogEntry;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'gate_entry_id', 'subject_type', 'subject_id'];

    public AuditLogEntry $entry;

    public function mount(AuditLogEntry $entry): void
    {
        $user = auth()->user();

        // Vendor is the one externally-facing role — block a vendor from
        // opening another vendor's record by guessing the URL. Every other
        // role is internal staff already trusted with cross-role visibility
        // elsewhere (e.g. Admin's Activity Log, Validation Issues). Rows with
        // no linked subject (e.g. logged without a $subject argument) have no
        // vendor to check against, so they fall through to the "no
        // structured record" state below instead of a 403.
        if ($user->role === Role::Vendor && $entry->subject && $entry->vendorName() !== $user->name) {
            abort(403);
        }

        $this->entry = $entry;
    }

    public function with(): array
    {
        $subject = $this->entry->subject;
        $gateEntry = $subject && method_exists($subject, 'gateEntry') ? $subject->gateEntry : null;

        return [
            'subjectLabel' => $subject ? $this->labelFor($subject) : null,
            'fields' => $subject ? $this->describe($subject) : [],
            'gateEntry' => $gateEntry,
            'gateEntryFields' => $gateEntry ? $this->describe($gateEntry) : [],
        ];
    }

    private function labelFor(Model $subject): string
    {
        return str(class_basename($subject))->headline()->toString();
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

<div class="max-w-3xl mx-auto">
    <a href="{{ url()->previous() }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">&larr; Back</a>

    <h1 class="text-xl font-semibold mt-2 mb-1" style="color: var(--text-primary);">{{ $entry->action }}</h1>
    <p class="text-sm mb-5" style="color: var(--text-secondary);">{{ $entry->created_at->format('d M Y, H:i') }}{{ $entry->detail ? ' · '.$entry->detail : '' }}</p>

    @if ($fields)
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">{{ $subjectLabel }} — submitted details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                @foreach ($fields as $f)
                    <div class="text-sm">
                        <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                        <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($gateEntry)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Related gate entry — {{ $gateEntry->gate_no }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                    @foreach ($gateEntryFields as $f)
                        <div class="text-sm">
                            <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                            <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="rounded-lg border p-4 text-sm" style="background: var(--surface-3); border-color: var(--border); color: var(--text-muted);">
            No structured record is linked to this activity entry (it may have been removed since).
        </div>
    @endif
</div>
