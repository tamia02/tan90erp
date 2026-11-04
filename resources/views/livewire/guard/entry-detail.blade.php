<?php

use App\Models\GateEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // Same latent bug as shared/gate-entry-detail.blade.php: toArray() dumps
    // any eager-loaded relation (validationIssues here) as raw JSON unless
    // explicitly excluded.
    // loadMissing() below adds these relations for the formatted completion
    // summary. Never let Eloquent's toArray() turn them into raw JSON fields.
    private const HIDDEN_FIELDS = [
        'id', 'created_at', 'updated_at', 'gate_entry_id', 'validation_issues',
        'unloading_record', 'qc_result', 'grn_record', 'product_lines',
    ];
    private const USER_FIELDS = ['created_by', 'approved_by', 'store_exec_verified_by', 'putaway_by', 'visitor_host_id'];

    public GateEntry $entry;

    public function mount(GateEntry $entry): void
    {
        $this->entry = $entry;
    }

    // Guard is the one physically at the gate for both directions -- they
    // log the vehicle in on arrival and, for outward, confirm it out once
    // Store Exec has finished loading and handed over documents.
    public function confirmExit(): void
    {
        if ($this->entry->entry_type !== 'outward' || $this->entry->status !== 'loaded') {
            return;
        }

        // Pass made from an Outward Shipment: the truck leaving IS the dispatch,
        // so stock goes down now - all lines or none. Short stock blocks the exit.
        try {
            $shipment = app(\App\Services\Outward\GateExitService::class)->confirm($this->entry, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('exit', collect($e->errors())->flatten()->implode(' '));

            return;
        }

        session()->flash('success', "Vehicle exit confirmed for {$this->entry->gate_no}. Outward dispatch is complete.".($shipment ? " {$shipment->number}: stock has been deducted." : ''));
    }

    // Mirrors confirmExit() for the visitor journey: the host has approved
    // (status=validated on Visitor Approvals), Guard lets them through the
    // gate. Confirmed live: this used to jump straight to "closed" with no
    // record of when the visitor actually arrived or left -- now a real
    // check-in/check-out pair, closed only once they leave.
    public function allowEntry(): void
    {
        if ($this->entry->entry_type !== 'visitor' || $this->entry->status !== 'validated') {
            return;
        }

        $this->entry->update(['status' => 'checked_in', 'visitor_checked_in_at' => now()]);

        \App\Services\AuditLogger::log('Visitor checked in', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
        session()->flash('success', "Visitor checked in for pass {$this->entry->gate_no}.");
    }

    public function checkOutVisitor(): void
    {
        if ($this->entry->entry_type !== 'visitor' || $this->entry->status !== 'checked_in') {
            return;
        }

        $this->entry->update(['status' => 'closed', 'visitor_checked_out_at' => now()]);

        \App\Services\AuditLogger::log('Visitor checked out', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
        session()->flash('success', "Visitor checked out. Pass {$this->entry->gate_no} is closed.");
    }

    public function with(): array
    {
        return $this->pdfData();
    }

    /** Shared by the on-screen render and the PDF export. */
    private function pdfData(): array
    {
        // A detail page can stay open while another role completes unloading,
        // QC, GRN, or putaway. Refresh the bound row and its loaded relations
        // on every render so the Guard does not need a manual page reload.
        $this->entry->refresh();
        $this->entry->loadMissing(['validationIssues', 'unloadingRecord', 'qcResult', 'grnRecord']);

        $visitorStatus = match ($this->entry->status) {
            'pending_validation' => ['Awaiting host approval', 'var(--status-warning)'],
            'validated' => ['Approved · awaiting gate check-in', 'var(--status-good)'],
            'checked_in' => ['Visitor checked in', 'var(--status-good)'],
            'closed' => ['Visitor checked out · pass complete', 'var(--text-muted)'],
            'rejected' => ['Entry denied', 'var(--status-critical)'],
            default => [str($this->entry->status)->headline(), 'var(--text-muted)'],
        };

        return [
            'entry' => $this->entry,
            'qrDataUri' => (new Builder(
                writer: new SvgWriter(),
                data: route('guard.entries.show', $this->entry),
                size: 160,
                margin: 8,
            ))->build()->getDataUri(),
            'fields' => $this->describe($this->entry),
            'issues' => $this->entry->validationIssues,
            'qcFields' => [],
            'grnFields' => [],
            'financeFields' => [],
            'completionSummary' => $this->completionSummary(),
            'gatePassStatus' => $this->entry->entry_type === 'visitor'
                ? ['Visitor status: '.$visitorStatus[0], $visitorStatus[1]]
                : ($this->entry->dock_assigned_at
                    ? ['Gate pass valid · dock assigned', 'var(--status-good)']
                    : ['Gate pass pending · dock not assigned', 'var(--status-warning)']),
        ];
    }

    /** Show the completed inward milestones on the guard's record view. */
    private function completionSummary(): array
    {
        $unloading = $this->entry->unloadingRecord;
        $qc = $this->entry->qcResult;
        $grn = $this->entry->grnRecord;

        return [
            ['label' => 'Dock assigned', 'value' => $this->entry->dock_assigned_at],
            ['label' => 'Unloading allotted', 'value' => $unloading?->allotted_at],
            ['label' => 'Unloading started', 'value' => $unloading?->started_at],
            ['label' => 'Unloading completed', 'value' => $unloading?->completed_at],
            ['label' => 'Boxes received', 'value' => $unloading?->box_count ? $unloading->box_count.' '.($unloading->packing_unit ?: 'units') : null],
            ['label' => 'QC accepted', 'value' => $qc?->accepted_qty],
            ['label' => 'QC hold', 'value' => $qc?->qc_hold_qty],
            ['label' => 'QC rejected', 'value' => $qc?->rejected_qty],
            ['label' => 'QC defective', 'value' => $qc?->defective_qty],
            ['label' => 'GRN', 'value' => $grn?->posted ? 'Posted' : ($qc ? 'Pending posting' : null)],
            ['label' => 'Putaway bin', 'value' => $this->entry->final_bin],
            ['label' => 'Putaway completed', 'value' => $this->entry->putaway_completed_at],
            ['label' => 'Store Executive verified', 'value' => $this->entry->store_exec_verified_at],
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

    public function downloadExcel(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $html = view('pdf.gate-entry', $this->pdfData())->render();
        return \App\Support\HtmlToXlsx::download($html, $this->entry->gate_no);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function describe(Model $subject): array
    {
        $fields = collect($subject->toArray())->except(self::HIDDEN_FIELDS);

        if ($subject instanceof GateEntry) {
            $directionOnlyFields = match ($subject->entry_type) {
                'inward' => [
                    'visitor_host_id', 'visitor_checked_in_at', 'visitor_checked_out_at',
                    'loading_window_start', 'loading_window_end', 'expected_delivery_date',
                    'loading_started_at', 'dispatch_documents_shared', 'loaded_at', 'exited_at',
                ],
                'outward' => [
                    'visitor_host_id', 'visitor_checked_in_at', 'visitor_checked_out_at',
                    'putaway_by', 'putaway_completed_at', 'final_bin',
                ],
                'visitor' => [
                    'contact_user_id', 'po_number', 'po_bill_date', 'vendor_gst', 'invoice_number', 'invoice_qty',
                    'invoice_amount', 'rate', 'transporter', 'loading_dock', 'dock_assigned_at',
                    'loading_window_start', 'loading_window_end', 'expected_delivery_date',
                    'loading_started_at', 'dispatch_documents_shared', 'loaded_at', 'exited_at',
                    'putaway_by', 'putaway_completed_at', 'final_bin',
                ],
                default => [],
            };

            $fields = $fields->except($directionOnlyFields);
        }

        return $fields
            ->map(function ($value, $key) use ($subject) {
                $label = str($key)->replace('_', ' ')->headline()->toString();

                // See shared/gate-entry-detail.blade.php's identical fix.
                if ($subject instanceof GateEntry && $subject->entry_type === 'outward') {
                    $label = match ($key) {
                        'po_number' => 'Package Number',
                        'vendor_name' => 'Delivery Address',
                        default => $label,
                    };
                }

                if ($key === 'contact_user_id') {
                    $label = 'Meeting Whom';
                    $contact = $value ? \App\Models\User::find($value) : null;
                    $value = $contact ? $contact->name.($contact->phone ? ' · '.$contact->phone : '') : null;
                }

                if ($subject instanceof GateEntry && $key === 'po_number') {
                    $value = $subject->maskedPoNumber();
                }

                if ($subject instanceof GateEntry && in_array($key, self::USER_FIELDS, true) && $value) {
                    $value = \App\Models\User::find($value)?->name ?? "User #{$value}";
                }

                return ['label' => $label, 'value' => $this->formatValue($value)];
            })
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
            // See shared/gate-entry-detail.blade.php's identical fix -- toArray()
            // serializes datetimes as UTC regardless of app.timezone.
            return \Illuminate\Support\Carbon::parse($value)->setTimezone(config('app.timezone'))->format('d M Y, H:i');
        }

        return (string) $value;
    }
}; ?>

<div class="space-y-5">
    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-xl border px-4 py-3 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <a href="{{ route('guard.entries') }}" wire:navigate class="text-xs font-semibold inline-flex items-center gap-1" style="color: var(--brand);">
                    <x-icon name="chevron-left" class="w-3.5 h-3.5" /> Back to Guard Entries
                </a>
                <div class="text-xs font-semibold uppercase tracking-wide mt-3" style="color: var(--brand);">Guard Module</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $entry->gate_no }}</h1>
                <div class="mt-2 inline-flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-2); color: var(--text-primary);">
                    @if ($entry->entry_type !== 'visitor')
                        <span class="font-semibold">{{ $entry->entry_type === 'outward' ? 'Package reference:' : 'PO reference:' }}</span> {{ $entry->maskedPoNumber() }}
                    @endif
                    <span class="text-xs" style="color: {{ $gatePassStatus[1] }};">{{ $gatePassStatus[0] }}</span>
                </div>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Full gate entry form as submitted, plus every stage it has moved through since.</p>
                <nav aria-label="Guard entry shortcuts" class="flex flex-wrap gap-2 mt-4 pt-3 border-t" style="border-color: var(--border);">
                    <a href="{{ route('guard.scan') }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--brand-bg); color: var(--brand);">New gate entry</a>
                    <a href="{{ route('guard.entries', ['type' => 'inward']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--brand);">Recent inward</a>
                    <a href="{{ route('guard.entries', ['type' => 'outward']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--status-warning);">Recent outward</a>
                    <a href="{{ route('guard.entries', ['type' => 'visitor']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-secondary);">Recent visitors</a>
                </nav>
            </div>
            <div class="flex gap-2 shrink-0">
                @error('exit') <div role="alert" class="text-xs rounded-lg border px-3 py-2 max-w-md" style="border-color: var(--status-critical); color: var(--status-critical); background: var(--status-critical-bg);">{{ $message }}</div> @enderror
                @if ($entry->entry_type === 'outward' && $entry->status === 'loaded')
                    <button wire:click="confirmExit" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">
                        <x-icon name="check-circle" class="w-4 h-4" /> Confirm exit
                    </button>
                @endif
                @if ($entry->entry_type === 'visitor' && $entry->status === 'validated')
                    <button wire:click="allowEntry" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">
                        <x-icon name="check-circle" class="w-4 h-4" /> Allow entry
                    </button>
                @endif
                @if ($entry->entry_type === 'visitor' && $entry->status === 'checked_in')
                    <button wire:click="checkOutVisitor" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">
                        <x-icon name="check-circle" class="w-4 h-4" /> Check out visitor
                    </button>
                @endif
                <button wire:click="downloadPdf" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                    <x-icon name="file-text" class="w-4 h-4" /> Download PDF
                </button>
                <button wire:click="downloadExcel" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Download Excel</button>
            </div>
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

    @if ($entry->entry_type === 'inward')
        <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Inward completion summary</h2>
                <span class="text-xs font-semibold rounded-full px-2 py-0.5" style="background: {{ $entry->status === 'closed' ? 'var(--status-good-bg)' : 'var(--status-warning-bg)' }}; color: {{ $entry->status === 'closed' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $entry->status === 'closed' ? 'Complete' : 'In progress' }}</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3">
                @foreach ($completionSummary as $f)
                    <div class="text-sm">
                        <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                        <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] instanceof \Carbon\CarbonInterface ? $f['value']->format('d M Y, H:i') : ($f['value'] ?? '—') }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($issues->isNotEmpty())
        <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Validation issues</h2>
            <p class="text-xs mb-3" style="color: var(--text-muted);">Raised automatically against PO / Vendor / SKU master data at save time. Current entry status: {{ ucwords(str_replace('_', ' ', (string) $entry->status)) }}. Each issue below shows its severity and current review status.</p>
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
