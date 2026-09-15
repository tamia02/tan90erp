<?php

use App\Models\GateEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'gate_entry_id'];

    public GateEntry $entry;

    public function mount(GateEntry $entry): void
    {
        $this->entry = $entry;
    }

    public function with(): array
    {
        return $this->pdfData();
    }

    /** Shared by the on-screen render and the PDF export. */
    private function pdfData(): array
    {
        $this->entry->loadMissing(['validationIssues']);

        return [
            'entry' => $this->entry,
            'fields' => $this->describe($this->entry),
            'issues' => $this->entry->validationIssues,
            'qcFields' => [],
            'grnFields' => [],
            'financeFields' => [],
        ];
    }

    public function downloadPdf(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $pdf = Pdf::loadView('pdf.gate-entry', $this->pdfData());

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "{$this->entry->gate_no}.pdf",
        );
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

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <a href="{{ route('guard.entries') }}" wire:navigate class="text-xs font-semibold inline-flex items-center gap-1" style="color: var(--brand);">
                    <x-icon name="chevron-left" class="w-3.5 h-3.5" /> Back to Guard Entries
                </a>
                <div class="text-xs font-semibold uppercase tracking-wide mt-3" style="color: var(--brand);">Guard Module</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $entry->gate_no }}</h1>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Full gate entry form as submitted, plus every stage it has moved through since.</p>
            </div>
            <button wire:click="downloadPdf" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                <x-icon name="file-text" class="w-4 h-4" /> Download PDF
            </button>
        </div>
    </section>

    <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Gate entry — submitted details</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3">
            @foreach ($fields as $f)
                <div class="text-sm">
                    <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                    <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($issues->isNotEmpty())
        <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Validation issues</h2>
            <p class="text-xs mb-3" style="color: var(--text-muted);">Raised automatically against PO / Vendor / SKU master data at save time. Hard-fail and red-flag issues below are why this entry is held at "Pending Validation" — the Store Manager clears them once the underlying master data or paperwork is corrected.</p>
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

</div>
