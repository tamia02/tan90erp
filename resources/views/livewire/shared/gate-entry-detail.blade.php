<?php

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\UnloadingRecord;
use App\Models\VendorSubmission;
use App\Support\ReportColumns;
use App\Support\VendorIdentity;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
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
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'gate_entry_id', 'qc_result', 'grn_record', 'finance_record', 'unloading_record', 'purchase_order', 'evidence_image_records', 'validation_issues', 'product_lines', 'bill_document_path', 'pod_document_path', 'evidence_images', 'evidence_image_type'];

    // Internal-only, even within the base gate entry fields Vendor can see:
    // the negotiated rate and internal SLA/ops metadata aren't the vendor's
    // to see, same reasoning as blocking the downstream QC/GRN/Finance
    // sections entirely for them.
    private const VENDOR_HIDDEN_FIELDS = ['rate', 'sla_deadline', 'remarks', 'bill_document_path', 'created_by', 'approved_by', 'putaway_by'];

    public GateEntry $entry;
    public array $grnReportColumns = ['sku', 'po_qty', 'physical_received', 'accepted_qty', 'rejected_qty', 'defective_qty', 'qc_hold_qty', 'missing_qty', 'unit_price', 'invoice_amount', 'accepted_value', 'suggested_bin'];

    public function updatedGrnReportColumns(): void
    {
        $this->grnReportColumns = ReportColumns::selectedGrn($this->grnReportColumns);
    }

    public function mount(GateEntry $entry): void
    {
        $user = auth()->user();

        if ($user->role === Role::Vendor && ! VendorIdentity::names($user)->contains($entry->vendor_name) && ! VendorIdentity::isShared($user)) {
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
        if (! auth()->user()->holdsRole(Role::Guard) || $this->entry->entry_type !== 'outward' || $this->entry->status !== 'loaded') {
            return;
        }

        $this->entry->update(['status' => 'dispatched', 'exited_at' => now()]);

        \App\Services\AuditLogger::log('Outward vehicle exited premises', $this->entry->gate_no, $this->entry);
        session()->flash('success', "Vehicle exit confirmed for {$this->entry->gate_no}. Outward dispatch is complete.");
    }

    public function allowEntry(): void
    {
        if (! auth()->user()->holdsRole(Role::Guard) || $this->entry->entry_type !== 'visitor' || $this->entry->status !== 'validated') {
            return;
        }

        $this->entry->update(['status' => 'checked_in', 'visitor_checked_in_at' => now()]);

        \App\Services\AuditLogger::log('Visitor allowed entry', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
        session()->flash('success', "Visitor checked in for pass {$this->entry->gate_no}.");
    }

    public function checkOutVisitor(): void
    {
        if (! auth()->user()->holdsRole(Role::Guard) || $this->entry->entry_type !== 'visitor' || $this->entry->status !== 'checked_in') {
            return;
        }

        $this->entry->update(['status' => 'closed', 'visitor_checked_out_at' => now()]);

        \App\Services\AuditLogger::log('Visitor checked out', $this->entry->gate_no.' · '.$this->entry->driver_name, $this->entry);
        session()->flash('success', "Visitor checked out. Pass {$this->entry->gate_no} is closed.");
    }

    public function verifyGuardEntry(): void
    {
        $user = auth()->user();
        $canVerify = $user->holdsRole(Role::StoreExec)
            || app(\App\Services\Access\AccessControlService::class)->can($user, 'unloading.records.manage');

        if (! $canVerify
            || $this->entry->entry_type !== 'inward'
            || ! in_array($this->entry->status, ['validated', 'dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted', 'closed'], true)
            || $this->entry->store_exec_verified_at) {
            return;
        }

        $this->entry->update([
            'store_exec_verified_by' => auth()->id(),
            'store_exec_verified_at' => now(),
        ]);
        $this->entry->refresh();

        \App\Services\AuditLogger::log('Guard entry verified by Store Executive', $this->entry->gate_no.' · '.$this->entry->po_number, $this->entry);
        session()->flash('success', "Guard entry {$this->entry->gate_no} verified. The inward workflow can continue.");
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
            $showDownstream ? 'qcResult.evidenceImageRecords' : null,
            $showDownstream ? 'grnRecord' : null,
            $showDownstream ? 'financeRecord' : null,
            $showDownstream ? 'unloadingRecord' : null,
            $user->role !== Role::Guard && $this->entry->entry_type === 'inward' ? 'purchaseOrder.lines' : null,
        ]));

        $fields = $this->describe($this->entry);
        if ($isVendor) {
            $fields = array_values(array_filter(
                $fields,
                fn ($f) => ! in_array(str($f['label'])->snake()->toString(), self::VENDOR_HIDDEN_FIELDS, true)
            ));
        }

        $purchaseOrder = $user->role !== Role::Guard && $this->entry->entry_type === 'inward'
            ? $this->entry->purchaseOrder
            : null;
        $vendorSubmission = $this->matchingVendorSubmission();

        return [
            'entry' => $this->entry,
            'qrDataUri' => (new Builder(
                writer: new SvgWriter(),
                data: route('gate-entries.show', $this->entry),
                size: 160,
                margin: 8,
            ))->build()->getDataUri(),
            'fields' => $fields,
            'purchaseOrder' => $purchaseOrder,
            'purchaseOrderFields' => $purchaseOrder ? $this->purchaseOrderFields($purchaseOrder) : [],
            'invoiceLines' => $user->role !== Role::Guard && $this->entry->entry_type === 'inward' ? $this->invoiceLines($purchaseOrder, $vendorSubmission) : [],
            'vendorSubmission' => $vendorSubmission,
            'submissionFields' => $vendorSubmission ? $this->submissionFields($vendorSubmission) : [],
            'submissionDocuments' => $vendorSubmission ? $this->submissionDocuments($vendorSubmission) : [],
            'unloadingFields' => $showDownstream && $this->entry->entry_type === 'inward' ? $this->unloadingDetails($this->entry->unloadingRecord) : [],
            'inwardProgress' => $showDownstream && $this->entry->entry_type === 'inward' ? $this->inwardProgressLabel() : null,
            'issues' => $this->entry->validationIssues,
            'qcFields' => $showDownstream && $this->entry->qcResult ? $this->describe($this->entry->qcResult) : [],
            'qcEvidenceImages' => $showDownstream && $this->entry->qcResult ? $this->entry->qcResult->evidenceImageRecords : collect(),
            'grnFields' => $showDownstream && $this->entry->grnRecord ? $this->describe($this->entry->grnRecord) : [],
            'grnReportColumns' => ReportColumns::selectedGrn($this->grnReportColumns),
            'grnColumnLabels' => ReportColumns::GRN,
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

    /** Never substitute a different split shipment when the invoice number is known. */
    private function matchingVendorSubmission(): ?VendorSubmission
    {
        if (! $this->entry->po_number || ! $this->entry->vendor_name || ! $this->entry->invoice_number) {
            return null;
        }

        return VendorSubmission::query()
            ->where('po_number', $this->entry->po_number)
            ->where('vendor_name', $this->entry->vendor_name)
            ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($this->entry->invoice_number)])
            ->latest('id')
            ->first();
    }

    /** @return array<int, array{label: string, value: string}> */
    private function purchaseOrderFields(PurchaseOrder $purchaseOrder): array
    {
        $address = function (string $prefix) use ($purchaseOrder) {
            $parts = [
                'Building' => $purchaseOrder->{$prefix.'_building'},
                'Street' => $purchaseOrder->{$prefix.'_street'},
                'City' => $purchaseOrder->{$prefix.'_city'},
                'State' => $purchaseOrder->{$prefix.'_state'},
                'PIN' => $purchaseOrder->{$prefix.'_zip'},
                'Country' => $purchaseOrder->{$prefix.'_country'},
            ];
            $recorded = collect($parts)->filter(fn ($part) => filled($part));

            if ($recorded->isEmpty()) {
                return 'Not recorded on PO';
            }

            $missing = collect($parts)->except('Country')->filter(fn ($part) => blank($part))->keys();
            $value = $recorded->implode(', ');

            return $missing->isEmpty() ? $value : $value.' · Incomplete; missing '.$missing->implode(', ');
        };

        return collect([
            ['label' => 'PO Number', 'value' => $purchaseOrder->po_number],
            ['label' => 'PO Subject', 'value' => $purchaseOrder->subject ?: 'Not recorded on PO'],
            ['label' => 'Vendor', 'value' => $purchaseOrder->vendor_name],
            ['label' => 'PO Date', 'value' => $purchaseOrder->po_date?->format('d M Y') ?: 'Not recorded on PO'],
            ['label' => 'Due Date', 'value' => $purchaseOrder->due_date?->format('d M Y') ?: 'Not recorded on PO'],
            ['label' => 'PO Status', 'value' => $purchaseOrder->status],
            ['label' => 'PO Owner', 'value' => $purchaseOrder->po_owner],
            ['label' => 'Requisition Number', 'value' => $purchaseOrder->requisition_number],
            ['label' => 'Contact', 'value' => $purchaseOrder->contact_name],
            ['label' => 'Carrier', 'value' => $purchaseOrder->carrier],
            ['label' => 'Tracking Number', 'value' => $purchaseOrder->tracking_number],
            ['label' => 'Billing Address', 'value' => $address('billing') ?: 'Not recorded on PO'],
            ['label' => 'Delivery Address', 'value' => $address('shipping') ?: 'Not recorded on PO'],
            ['label' => 'Payment Terms', 'value' => $purchaseOrder->payment_terms],
            ['label' => 'Purchase Nature', 'value' => $purchaseOrder->purchase_nature],
            ['label' => 'Material Type', 'value' => $purchaseOrder->material_type],
            ['label' => 'Warehouse Location', 'value' => $purchaseOrder->warehouse_location],
            ['label' => 'Shipment Preference', 'value' => $purchaseOrder->shipment_preference],
            ['label' => 'Tax Level', 'value' => $purchaseOrder->tax_level],
            ['label' => 'Reverse Charge', 'value' => $purchaseOrder->reverse_charge === null ? null : ($purchaseOrder->reverse_charge ? 'Yes' : 'No')],
            ['label' => 'Description', 'value' => $purchaseOrder->description],
            ['label' => 'Terms & Conditions', 'value' => $purchaseOrder->terms_and_conditions],
            ['label' => 'Additional PO Fields', 'value' => $this->formatCustomFields($purchaseOrder->header_custom_fields)],
            ['label' => 'PO Total', 'value' => $purchaseOrder->lines->isNotEmpty() ? '₹'.number_format($purchaseOrder->grandTotal(), 2) : null],
        ])->filter(fn ($field) => $field['value'] !== null && $field['value'] !== '')->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function invoiceLines(?PurchaseOrder $purchaseOrder, ?VendorSubmission $submission = null): array
    {
        $poLines = $purchaseOrder?->lines?->keyBy('id') ?? collect();
        $scannedLines = collect($this->entry->product_lines ?? [])->values();

        if ($scannedLines->isEmpty() && is_array($submission?->line_quantities) && $submission->line_quantities !== []) {
            $scannedLines = $poLines->filter(fn (PurchaseOrderLine $line) => (float) ($submission->line_quantities[$line->id] ?? 0) > 0)
                ->map(fn (PurchaseOrderLine $line) => [
                    'po_line_id' => $line->id,
                    'sku' => $line->product,
                    'name' => $line->product,
                    'qty' => (float) $submission->line_quantities[$line->id],
                    'uom' => $line->uom,
                ])->values();
        }

        if ($scannedLines->isEmpty()) {
            $scannedLines = $poLines->values()->map(fn (PurchaseOrderLine $line) => [
                'po_line_id' => $line->id,
                'sku' => $line->product,
                'name' => $line->product,
                'qty' => $poLines->count() === 1 ? $this->entry->invoice_qty : null,
                'uom' => $line->uom,
            ]);
        }

        if ($scannedLines->isEmpty() && filled($this->entry->material)) {
            $scannedLines = collect([[
                'sku' => $this->entry->material,
                'name' => $this->entry->material,
                'qty' => $this->entry->invoice_qty,
            ]]);
        }

        return $scannedLines->map(function (array $line) use ($poLines) {
            $poLine = $poLines->get($line['po_line_id'] ?? null);
            $quantity = $line['qty'] ?? null;

            return [
                'item' => $line['name'] ?? $line['sku'] ?? $poLine?->product ?? $this->entry->material,
                'hsn_sac' => $poLine?->hsn_sac ?? $line['hsn_sac'] ?? null,
                'ordered_qty' => $poLine?->quantity,
                'invoice_qty' => $quantity,
                'uom' => $line['uom'] ?? $poLine?->uom,
                'unit_rate' => auth()->user()->role === Role::Vendor ? null : ($poLine?->list_price ?? $line['unit_price'] ?? $this->entry->rate),
                'line_details' => $poLine ? collect([
                    $poLine->discount !== null ? 'Discount ₹'.number_format((float) $poLine->discount, 2) : null,
                    $poLine->tax !== null ? 'Tax ₹'.number_format((float) $poLine->tax, 2) : null,
                    $poLine->gst_rate !== null ? 'GST '.number_format((float) $poLine->gst_rate, 2).'%' : null,
                    $this->formatCustomFields($poLine->custom_fields),
                ])->filter()->implode(' · ') : null,
            ];
        })->all();
    }

    /** @return array<int, array{label: string, value: string}> */
    private function submissionFields(VendorSubmission $submission): array
    {
        return collect([
            ['label' => 'Invoice Number', 'value' => $submission->invoice_number],
            ['label' => 'Invoice Date', 'value' => $submission->invoice_date?->format('d M Y') ?: 'Not recorded'],
            ['label' => 'Invoice Quantity', 'value' => $submission->invoice_qty ?? 'Not recorded'],
            ['label' => 'Invoice Amount', 'value' => $submission->invoice_amount !== null ? '₹'.number_format((float) $submission->invoice_amount, 2) : 'Not recorded'],
            ['label' => 'Submission Status', 'value' => str($submission->status)->replace('_', ' ')->title()->toString()],
            ['label' => 'Expected Arrival', 'value' => $submission->expected_arrival_at?->format('d M Y, H:i') ?: 'Not scheduled'],
            ['label' => 'Vehicle', 'value' => $submission->vehicle_number ?: 'Not recorded'],
            ['label' => 'Production Started', 'value' => $submission->production_started_at?->format('d M Y, H:i') ?: 'Not recorded'],
            ['label' => 'Dispatched At', 'value' => $submission->dispatched_at?->format('d M Y, H:i') ?: 'Not dispatched'],
            ['label' => 'TAT Deadline / Expected Arrival', 'value' => ($submission->tat_due_at ?? $submission->expected_arrival_at)?->format('d M Y, H:i') ?: 'Not scheduled'],
            ['label' => 'Vendor Note', 'value' => $submission->note ?: 'No note'],
        ])->filter(fn ($field) => $field['value'] !== null && $field['value'] !== '')->values()->all();
    }

    /** Show the operational checkpoints even on older records where a value was not captured. */
    private function unloadingDetails(?UnloadingRecord $record): array
    {
        if (! $record) {
            return [];
        }

        return [
            ['label' => 'Physical Count', 'value' => $record->box_count ?? 'Not recorded'],
            ['label' => 'Packing Unit', 'value' => $record->packing_unit ?: 'Not recorded'],
            ['label' => 'Staging Area', 'value' => $record->staging_area ?: 'Not recorded'],
            ['label' => 'Manpower Count', 'value' => $record->manpower_count ?? 'Not recorded'],
            ['label' => 'Unloaded By', 'value' => $record->unloaded_by ?: 'Not recorded'],
            ['label' => 'POD / LR Reference', 'value' => $record->pod_lr_ref ?: 'Not provided'],
            ['label' => 'POD Evidence', 'value' => $record->pod_document_path ? 'File attached' : 'No file uploaded'],
            ['label' => 'Allotted At', 'value' => $record->allotted_at?->format('d M Y, H:i') ?: 'Not recorded'],
            ['label' => 'Started At', 'value' => $record->started_at?->format('d M Y, H:i') ?: 'Not started'],
            ['label' => 'Completed At', 'value' => $record->completed_at?->format('d M Y, H:i') ?: 'Not completed'],
        ];
    }

    private function formatCustomFields(mixed $fields): ?string
    {
        if (! is_array($fields) || $fields === []) {
            return null;
        }

        return collect($fields)->map(function ($field, $key) {
            if (is_array($field)) {
                $label = $field['label'] ?? $field['name'] ?? $field['field_name'] ?? (string) $key;
                $value = $field['value'] ?? $field['field_value'] ?? $field['text'] ?? null;
            } else {
                $label = str((string) $key)->replace('_', ' ')->headline()->toString();
                $value = $field;
            }

            return is_scalar($value) && filled($value) ? $label.': '.$value : null;
        })->filter()->implode(' · ') ?: null;
    }

    /** @return array<int, array{label: string, url: string}> */
    private function submissionDocuments(VendorSubmission $submission): array
    {
        return collect([
            ['label' => 'Invoice', 'key' => 'invoice', 'path' => $submission->invoice_document_path],
            ['label' => 'E-way bill', 'key' => 'eway', 'path' => $submission->eway_bill_document_path],
            ['label' => 'LR / POD', 'key' => 'lr', 'path' => $submission->lr_pod_document_path],
        ])->filter(fn ($document) => filled($document['path']))
            ->map(fn ($document) => ['label' => $document['label'], 'url' => route('vendor.submission.document', [$submission, $document['key']])])
            ->values()->all();
    }

    private function inwardProgressLabel(): string
    {
        return match (true) {
            $this->entry->status === 'closed' => 'Putaway complete · inward entry closed',
            $this->entry->status === 'grn_posted' => 'GRN posted · awaiting putaway',
            $this->entry->status === 'qc_done' && $this->entry->putaway_completed_at => 'Putaway complete · awaiting QC hold resolution and GRN posting',
            $this->entry->status === 'qc_done' => 'QC complete · awaiting GRN posting',
            $this->entry->status === 'grn' => 'Unloading complete · awaiting QC',
            $this->entry->status === 'unloading' => 'Unloading in progress',
            $this->entry->status === 'allotted' => 'Allotted · awaiting unloading start',
            $this->entry->status === 'dock_assigned' => 'Dock assigned · awaiting Store Executive allotment',
            default => str($this->entry->status)->replace('_', ' ')->title()->toString(),
        };
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

    public function downloadExcel(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $html = view('pdf.gate-entry', $this->pdfData())->render();
        return \App\Support\HtmlToXlsx::download($html, $this->entry->gate_no);
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
            ->reject(fn ($value, $key) => $subject instanceof GateEntry && $this->notApplicableGateField($subject, $key))
            ->reject(fn ($value) => $value === null || $value === '' || $value === [])
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
                    $subject instanceof GateEntry && auth()->user()->role === Role::Guard && $key === 'po_number'
                        => $subject->maskedPoNumber(),
                    in_array($key, ['created_by', 'approved_by', 'store_exec_verified_by', 'visitor_host_id', 'putaway_by'], true) && $value
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

    private function notApplicableGateField(GateEntry $entry, string $key): bool
    {
        if ($entry->entry_type === 'inward') {
            return in_array($key, ['loading_window_start', 'loading_window_end', 'expected_delivery_date', 'loading_started_at', 'dispatch_documents_shared', 'loaded_at', 'exited_at'], true);
        }

        if ($entry->entry_type === 'outward') {
            return in_array($key, ['po_bill_date', 'vendor_gst', 'putaway_by', 'putaway_completed_at', 'final_bin'], true);
        }

        return $entry->entry_type === 'visitor'
            && in_array($key, ['po_bill_date', 'vendor_gst', 'invoice_number', 'invoice_qty', 'invoice_amount', 'rate', 'material', 'transporter', 'bill_scanned', 'bill_document_path'], true);
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
            return empty($value)
                ? '—'
                : collect($value)->map(fn ($item, $key) => is_scalar($item) ? str($key)->replace('_', ' ')->headline().': '.$item : null)->filter()->implode(' · ');
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
    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
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
                @if (auth()->user()->holdsRole(\App\Enums\Role::Guard))
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
                @if ((auth()->user()->holdsRole(\App\Enums\Role::StoreExec) || app(\App\Services\Access\AccessControlService::class)->can(auth()->user(), 'unloading.records.manage')) && $entry->entry_type === 'inward')
                    @if ($entry->store_exec_verified_at)
                        <span class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold" style="background: var(--status-good-bg); color: var(--status-good);">
                            <x-icon name="check-circle" class="w-4 h-4" /> Guard verified {{ $entry->store_exec_verified_at->format('d M, H:i') }}
                        </span>
                    @elseif (in_array($entry->status, ['validated', 'dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted', 'closed'], true))
                        <button wire:click="verifyGuardEntry" wire:loading.attr="disabled" wire:target="verifyGuardEntry" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--status-good);">
                            <x-icon name="check-circle" class="w-4 h-4" /> Verify guard entry
                        </button>
                    @elseif ($entry->status === 'pending_validation')
                        <span class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Manager approval required first</span>
                        <button type="button" disabled class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border opacity-60 cursor-not-allowed" style="border-color: var(--border); color: var(--text-secondary);">Verify after approval</button>
                    @endif
                @endif
                <button wire:click="downloadPdf" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                    <x-icon name="file-text" class="w-4 h-4" /> Download PDF
                </button>
                <button wire:click="downloadExcel" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Download Excel</button>
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
        <div class="flex flex-wrap items-center gap-2 mt-4 border-t pt-3" style="border-color: var(--border);">
            <span class="text-xs font-semibold" style="color: var(--text-secondary);">Scanned bill:</span>
            @if ($entry->bill_document_path)
                <a href="{{ route('gate-entries.document', [$entry, 'bill']) }}" class="inline-flex min-h-10 items-center rounded-lg border px-3 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--brand);">Download bill copy</a>
            @else
                <span class="text-xs" style="color: var(--text-muted);">No bill file uploaded</span>
            @endif
        </div>
    </div>

    @if ($entry->entry_type === 'inward' && auth()->user()->role !== \App\Enums\Role::Guard)
        <section class="rounded-xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Purchase order &amp; received items</h2>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">PO and item details are linked by the PO number saved on this gate entry.</p>
                </div>
                @if ($purchaseOrder)
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--status-good-bg); color: var(--status-good);">PO linked</span>
                @endif
            </div>
            @if ($purchaseOrder)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 mb-4">
                    @foreach ($purchaseOrderFields as $f)
                        <div class="text-sm min-w-0">
                            <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                            <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div role="status" class="rounded-lg border px-3 py-2 mb-4 text-sm" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">
                    PO {{ $entry->po_number ?: '(no PO number recorded)' }} could not be matched to a purchase order. Gate-entry values below are retained; PO-controlled details are unavailable.
                </div>
            @endif

            @if (! empty($invoiceLines))
                <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Shipment lines on this invoice</div>
                <div class="overflow-x-auto rounded-lg border" style="border-color: var(--border);">
                    <table class="w-full min-w-[650px] text-left text-xs">
                        <thead style="background: var(--surface-2); color: var(--text-secondary);">
                            <tr>
                                <th class="px-3 py-2 font-semibold">Item / SKU</th>
                                <th class="px-3 py-2 font-semibold">HSN/SAC</th>
                                <th class="px-3 py-2 font-semibold text-right">PO Qty</th>
                                <th class="px-3 py-2 font-semibold text-right">This Invoice</th>
                                <th class="px-3 py-2 font-semibold">UOM</th>
                                @if (auth()->user()->role !== \App\Enums\Role::Vendor)<th class="px-3 py-2 font-semibold text-right">PO Unit Rate</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoiceLines as $line)
                                <tr class="border-t" style="border-color: var(--border); color: var(--text-primary);">
                                    <td class="px-3 py-2 font-medium">{{ $line['item'] ?: 'Unspecified item' }}@if ($line['line_details'])<div class="mt-1 text-[11px] font-normal" style="color: var(--text-muted);">{{ $line['line_details'] }}</div>@endif</td>
                                    <td class="px-3 py-2">{{ $line['hsn_sac'] ?: 'Not set on PO' }}</td>
                                    <td class="px-3 py-2 text-right">{{ filled($line['ordered_qty']) ? number_format((float) $line['ordered_qty'], 2) : 'Not recorded' }}</td>
                                    <td class="px-3 py-2 text-right">{{ filled($line['invoice_qty']) ? number_format((float) $line['invoice_qty'], 2) : 'Not recorded' }}</td>
                                    <td class="px-3 py-2">{{ $line['uom'] ?: 'Not specified' }}</td>
                                    @if (auth()->user()->role !== \App\Enums\Role::Vendor)<td class="px-3 py-2 text-right">{{ filled($line['unit_rate']) ? '₹'.number_format((float) $line['unit_rate'], 2) : 'Not recorded' }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs mt-2" style="color: var(--text-muted);">Invoice total: {{ $entry->invoice_amount !== null ? '₹'.number_format((float) $entry->invoice_amount, 2) : 'Not recorded' }}. For split shipments, this is the amount recorded for this gate entry only.</p>
            @elseif ($purchaseOrder)
                <p class="text-sm" style="color: var(--text-muted);">No PO item lines or scanned product lines are recorded for this entry.</p>
            @endif
        </section>

        <section class="rounded-xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Vendor invoice &amp; dispatch submission</h2>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Matched using PO number, vendor and this exact invoice number, so split shipments are not mixed.</p>
                </div>
                @if ($vendorSubmission)
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--brand-bg); color: var(--brand);">Submission matched</span>
                @endif
            </div>
            @if ($vendorSubmission)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                    @foreach ($submissionFields as $f)
                        <div class="text-sm min-w-0">
                            <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                            <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
                @if (! empty($submissionDocuments))
                    <div class="flex flex-wrap gap-2 mt-4" aria-label="Vendor-submitted documents">
                        @foreach ($submissionDocuments as $document)
                            <a href="{{ $document['url'] }}" class="inline-flex min-h-10 items-center rounded-lg border px-3 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--brand);" target="_blank" rel="noopener">View {{ $document['label'] }}</a>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs mt-3" style="color: var(--text-muted);">No invoice, e-way bill or LR/POD file was attached to this submission.</p>
                @endif
            @else
                <p class="text-sm" style="color: var(--text-secondary);">No vendor submission matches invoice {{ $entry->invoice_number ?: '(no invoice number recorded)' }}. The screen will not substitute another invoice from this PO.</p>
            @endif
        </section>

        @if (auth()->user()->role !== \App\Enums\Role::Vendor)
            <section class="rounded-xl border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                    <div>
                        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Physical unloading</h2>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">Store Executive record, linked to this exact gate entry.</p>
                    </div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--brand-bg); color: var(--brand);">{{ $inwardProgress }}</span>
                </div>
                @if (! empty($unloadingFields))
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                        @foreach ($unloadingFields as $f)
                            <div class="text-sm min-w-0">
                                <div class="text-xs" style="color: var(--text-muted);">{{ $f['label'] }}</div>
                                <div class="mt-0.5 break-words" style="color: var(--text-primary);">{{ $f['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                    @if ($entry->unloadingRecord?->pod_document_path)
                        <a href="{{ route('gate-entries.document', [$entry, 'pod']) }}" class="inline-flex min-h-10 items-center rounded-lg border px-3 py-2 mt-3 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--brand);">Download POD evidence</a>
                    @endif
                @else
                    <p class="text-sm" style="color: var(--text-secondary);">No unloading record has been saved for this gate entry yet.</p>
                @endif
            </section>
        @endif
    @endif

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
            @if ($qcEvidenceImages->isNotEmpty())
                <div class="mt-4 border-t pt-3" style="border-color: var(--border);">
                    <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">QC evidence images</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($qcEvidenceImages as $index => $evidence)
                            <a href="{{ route('qc.evidence-image', $evidence) }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-lg border px-3 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); color: var(--brand);">{{ $evidence->image_type ?: 'QC evidence' }} {{ $index + 1 }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($grnFields)
        <div class="rounded-lg border p-4 mb-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">GRN record</h2>
                @if ($entry->grnRecord?->posted)
                    <div class="flex flex-wrap gap-2"><a href="{{ route('grn.export.pdf', ['gate' => $entry, 'columns' => implode(',', $grnReportColumns)]) }}" class="rounded-lg border px-3 py-1.5 text-xs font-semibold" style="border-color: var(--border); color: var(--brand);">Download PDF</a><a href="{{ route('grn.export.excel', ['gate' => $entry, 'columns' => implode(',', $grnReportColumns)]) }}" class="rounded-lg border px-3 py-1.5 text-xs font-semibold" style="border-color: var(--border); color: var(--brand);">Download Excel</a></div>
                @endif
            </div>
            @if ($entry->grnRecord?->posted)
                <details class="rounded-lg border p-3 mb-3" style="border-color:var(--border);"><summary class="cursor-pointer text-xs font-semibold">Customize GRN report columns (PDF and Excel follow this selection)</summary><div class="flex flex-wrap gap-x-4 gap-y-2 mt-3">@foreach($grnColumnLabels as $column => $label)<label class="inline-flex gap-2 items-center text-xs"><input type="checkbox" wire:model.live="grnReportColumns" value="{{ $column }}">{{ $label }}</label>@endforeach</div></details>
            @endif
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
