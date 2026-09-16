<?php

use App\Enums\Role;
use App\Models\GateEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Client-reported gap: every downstream screen (Unloading Desk, QC, GRN,
// Finance) could act on a gate entry via its list cards, but had no way to
// open the full record - only Guard's own guard.entries.show did that, and
// that route is guard-only. This is the shared equivalent every other role
// links to, with QC/GRN/Finance sections gated by role: Guard and Vendor see
// only submitted fields + validation issues; every INTERNAL-staff role sees
// the full downstream chain, matching this app's existing "internal staff
// are cross-role trusted" pattern (see shared.activity-detail's own
// comment). Vendor is additionally blocked from opening another vendor's
// entry entirely.
//
// Confirmed live: this previously gated only on `role !== Guard`, which
// left Vendor (an external party, not internal staff) with the same full
// downstream visibility as Finance -- rate per unit, final payable, QC/GRN
// internal notes, all exposed to whichever vendor's name matched the entry.
new #[Layout('layouts.app')] class extends Component
{
    // Confirmed live: describe($this->entry) reads $entry->toArray(), which
    // Eloquent auto-includes every EAGER-LOADED RELATION on -- qcResult,
    // grnRecord, financeRecord and unloadingRecord are all loaded in
    // pdfData() below for the dedicated sections further down the page, but
    // toArray() also dumps each one as a raw JSON blob under a "QC Result" /
    // "GRN Record" / "Finance Record" / "Unloading Record" field in the TOP
    // "submitted details" grid -- literal, un-formatted json_encode() output,
    // appearing before any of the properly formatted sections.
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'gate_entry_id', 'qc_result', 'grn_record', 'finance_record', 'unloading_record', 'validation_issues'];

    // Internal-only, even within the base gate entry fields Vendor can see:
    // the negotiated rate and internal SLA/ops metadata aren't the vendor's
    // to see, same reasoning as blocking the downstream QC/GRN/Finance
    // sections entirely for them.
    private const VENDOR_HIDDEN_FIELDS = ['rate', 'sla_deadline', 'remarks', 'bill_document_path', 'created_by', 'approved_by', 'putaway_by'];

    public GateEntry $entry;

    public function mount(GateEntry $entry): void
    {
        $user = auth()->user();

        if ($user->role === Role::Vendor && $entry->vendor_name !== $user->name) {
            abort(403);
        }

        $this->entry = $entry;
    }

    // Confirmed live: Confirm Exit / Allow Entry only ever existed on
    // Guard's own guard.entries.show page -- "View Entry" links elsewhere
    // (Guard Entries list, notifications) can land Guard on this shared
    // page instead, which had neither action at all. Mirrors
    // guard/entry-detail.blade.php's identical methods.
    public function confirmExit(): void
    {
        if (auth()->user()->role !== Role::Guard || $this->entry->entry_type !== 'outward' || $this->entry->status !== 'loaded') {
            return;
        }

        $this->entry->update(['status' => 'dispatched', 'exited_at' => now()]);

        \App\Services\AuditLogger::log('Outward vehicle exited premises', $this->entry->gate_no, $this->entry);
    }

    public function allowEntry(): void
    {
        if (auth()->user()->role !== Role::Guard || $this->entry->entry_type !== 'visitor' || $this->entry->status !== 'validated') {
            return;
        }

        $this->entry->update(['status' => 'checked_in', 'visitor_checked_in_at' => now()]);

        \App\Services\AuditLogger::log('Visitor allowed entry', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
    }

    public function checkOutVisitor(): void
    {
        if (auth()->user()->role !== Role::Guard || $this->entry->entry_type !== 'visitor' || $this->entry->status !== 'checked_in') {
            return;
        }

        $this->entry->update(['status' => 'closed', 'visitor_checked_out_at' => now()]);

        \App\Services\AuditLogger::log('Visitor checked out', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
    }

    public function with(): array
    {
        return $this->pdfData();
    }

    /** Shared by the on-screen render and the PDF export -- same fields,
     * same downstream visibility rule, so the download can never show more
     * (or less) than what's actually on screen. */
    private function pdfData(): array
    {
        $user = auth()->user();
        $isVendor = $user->role === Role::Vendor;
        $showDownstream = ! in_array($user->role, [Role::Guard, Role::Vendor], true);

        $this->entry->loadMissing(array_filter([
            'validationIssues',
            $showDownstream ? 'qcResult' : null,
            $showDownstream ? 'grnRecord' : null,
            $showDownstream ? 'financeRecord' : null,
            $showDownstream ? 'unloadingRecord' : null,
        ]));

        $fields = $this->describe($this->entry);
        if ($isVendor) {
            $fields = array_values(array_filter(
                $fields,
                fn ($f) => ! in_array(str($f['label'])->snake()->toString(), self::VENDOR_HIDDEN_FIELDS, true)
            ));
        }

        return [
            'entry' => $this->entry,
            'fields' => $fields,
            'issues' => $this->entry->validationIssues,
            'qcFields' => $showDownstream && $this->entry->qcResult ? $this->describe($this->entry->qcResult) : [],
            'grnFields' => $showDownstream && $this->entry->grnRecord ? $this->describe($this->entry->grnRecord) : [],
            'financeFields' => $showDownstream && $this->entry->financeRecord ? $this->describe($this->entry->financeRecord) : [],
            // Confirmed live: the page's own subtitle promised "every stage
            // it has moved through since" but rendered nothing of the kind.
            // AuditLogEntry rows exist for every stage, just filed against
            // whichever model was the direct subject at that step (QcResult,
            // GrnRecord, etc.), not the gate entry itself -- so this needs
            // to look across all of them, not just GateEntry's own id.
            'timeline' => $showDownstream ? $this->timeline() : collect(),
        ];
    }

    private function timeline(): \Illuminate\Support\Collection
    {
        $subjects = collect([$this->entry])
            ->merge(array_filter([
                $this->entry->unloadingRecord,
                $this->entry->qcResult,
                $this->entry->grnRecord,
                $this->entry->financeRecord,
            ]));

        $query = \App\Models\AuditLogEntry::query()->where(function ($q) use ($subjects) {
            foreach ($subjects as $subject) {
                $q->orWhere(function ($q2) use ($subject) {
                    $q2->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
                });
            }
        });

        return $query->orderBy('created_at')->get();
    }

    public function downloadPdf(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $pdf = Pdf::loadView('pdf.gate-entry', $this->pdfData());

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "{$this->entry->gate_no}.pdf",
        );
    }

    // headline() alone reads oddly for the acronym-shaped column names this
    // schema actually uses -- confirmed live: "Po Number", "Gps", "Gst
    // Number" instead of "PO Number", "GPS", "GST Number".
    private const LABEL_OVERRIDES = ['Po' => 'PO', 'Gps' => 'GPS', 'Gst' => 'GST', 'Sla' => 'SLA', 'Qc' => 'QC', 'Grn' => 'GRN'];

    /** @return array<int, array{label: string, value: string}> */
    private function describe(Model $subject): array
    {
        return collect($subject->toArray())
            ->except(self::HIDDEN_FIELDS)
            ->map(function ($value, $key) use ($subject) {
                $label = str($key)->replace('_', ' ')->headline()->toString();
                foreach (self::LABEL_OVERRIDES as $wrong => $right) {
                    $label = preg_replace('/\b'.$wrong.'\b/', $right, $label);
                }

                // Confirmed live: an outward dispatch reuses the same
                // po_number/vendor_name columns for its own Package Number/
                // Delivery Address fields (same shared table as inward) --
                // the generic describe() had no idea, so those values showed
                // under "PO Number"/"Vendor Name" on an outward entry.
                if ($subject instanceof GateEntry && $subject->entry_type === 'outward') {
                    $label = match ($key) {
                        'po_number' => 'Package Number',
                        'vendor_name' => 'Delivery Address',
                        default => $label,
                    };
                }

                // created_by is a raw user id everywhere it appears -- confirmed
                // live as literally "1" on screen, meaningless to whoever's
                // reading it. approved_by/visitor_host_id/putaway_by are the
                // same column shape.
                $resolvedValue = match (true) {
                    in_array($key, ['created_by', 'approved_by', 'visitor_host_id', 'putaway_by'], true) && $value
                        => \App\Models\User::find($value)?->name ?? "User #{$value}",
                    $key === 'parameters' && is_array($value) && ! empty($value)
                        => collect($value)->map(fn ($p) => ($p['name'] ?? '?').': '.($p['value'] ?? '?').' ('.($p['result'] ?? '?').')')->implode('; '),
                    $key === 'documents_checked' && is_array($value) && ! empty($value)
                        => collect($value)->filter()->keys()->map(fn ($k) => ucfirst($k))->implode(', ') ?: 'None checked',
                    default => $this->formatValue($value),
                };

                return ['label' => $label, 'value' => $resolvedValue];
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
            // Confirmed live: toArray() serializes datetime attributes as
            // UTC ISO8601 (trailing Z) regardless of app.timezone -- parsing
            // that string without converting back to local time printed the
            // UTC wall-clock value as if it were already local (Approved At
            // showed 06:42 while the Timeline's own Carbon-object rendering
            // of the same instant correctly showed 12:12 IST).
            return \Illuminate\Support\Carbon::parse($value)->setTimezone(config('app.timezone'))->format('d M Y, H:i');
        }

        return (string) $value;
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <a href="{{ url()->previous() }}" wire:navigate class="text-xs font-semibold inline-flex items-center gap-1" style="color: var(--brand);">
                    <x-icon name="chevron-left" class="w-3.5 h-3.5" /> Back
                </a>
                <h1 class="text-2xl font-bold mt-3" style="color: var(--text-primary);">{{ $entry->gate_no }}</h1>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Full gate entry form as submitted{{ $timeline->isNotEmpty() ? ', plus every stage it has moved through since' : '' }}.</p>
            </div>
            <div class="flex gap-2 shrink-0">
                @if (auth()->user()->role === \App\Enums\Role::Guard)
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
                @endif
                <button wire:click="downloadPdf" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                    <x-icon name="file-text" class="w-4 h-4" /> Download PDF
                </button>
            </div>
        </div>
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

    @if ($timeline->isNotEmpty())
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Timeline</h2>
            <div class="flex flex-col gap-3">
                @foreach ($timeline as $event)
                    <div class="flex gap-3 text-sm">
                        <div class="w-2 h-2 rounded-full mt-1.5 shrink-0" style="background: var(--brand);"></div>
                        <div>
                            <div style="color: var(--text-primary);">{{ $event->action }}</div>
                            @if ($event->detail)
                                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $event->detail }}</div>
                            @endif
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $event->created_at->format('d M Y, H:i') }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
