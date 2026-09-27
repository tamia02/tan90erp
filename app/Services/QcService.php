<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Models\QcResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Ports the React prototype's SAVE_QC_RESULT reducer case — QC Check
// produces the accept/hold/defective/rejected split with reasons. Does NOT
// touch stock; GRN Check (GrnPostingService) reads this and is the only
// thing that posts to the ledger.
class QcService
{
    /**
     * Enforce QC invariants independently of the browser/component validation.
     *
     * @param  array{accepted?: mixed, qcHold?: mixed, defective?: mixed, rejected?: mixed}  $split
     * @param  array<int, array{path: string, type: string}>  $evidenceImages
     */
    public function validateQcInput(array $split, int $physicalQty, ?float $invoiceAmount, ?float $unitPrice, array $evidenceImages, ?string $holdReason = null): int
    {
        $quantities = [];
        foreach (['accepted', 'qcHold', 'defective', 'rejected'] as $key) {
            $value = $split[$key] ?? null;
            if ($key !== 'accepted' && ($value === null || (is_string($value) && trim($value) === ''))) {
                $value = 0;
            }
            if (! is_numeric($value) || (int) $value < 0 || (float) $value !== (float) (int) $value) {
                throw ValidationException::withMessages([$key => 'Enter a whole quantity of zero or more.']);
            }
            $quantities[$key] = (int) $value;
        }

        if ($physicalQty < 1) {
            throw ValidationException::withMessages(['physicalReceived' => 'Physical quantity must be greater than zero.']);
        }
        if ($invoiceAmount === null || $invoiceAmount < 0) {
            throw ValidationException::withMessages(['invoiceAmount' => 'Invoice / PO amount is required and cannot be negative.']);
        }
        if ($unitPrice === null || $unitPrice < 0) {
            throw ValidationException::withMessages(['unitPrice' => 'Unit price is required and cannot be negative.']);
        }

        $total = array_sum($quantities);
        if ($total !== $physicalQty) {
            throw ValidationException::withMessages([
                'accepted' => "Accepted + hold + defective + rejected must equal total physical quantity received ({$physicalQty}).",
            ]);
        }

        if ($quantities['qcHold'] > 0 && trim((string) $holdReason) === '') {
            throw ValidationException::withMessages([
                'holdReason' => 'Add a comment explaining the QC hold before sending this line to GRN Check.',
            ]);
        }

        // The client requires photo proof only for rejected/defective goods.
        // A hold is a temporary disposition and is justified by the mandatory
        // hold comment above; forcing a photo for every hold blocked legitimate
        // receipts where the issue was document/specification review.
        $evidenceRequired = $quantities['defective'] > 0 || $quantities['rejected'] > 0;
        if ($evidenceRequired && count($evidenceImages) < 2) {
            throw ValidationException::withMessages([
                'evidenceImages' => 'At least 2 images are required before a defective or rejected QC line can progress.',
            ]);
        }
        foreach ($evidenceImages as $image) {
            if (! is_array($image)
                || ! is_string($image['path'] ?? null)
                || trim($image['path']) === ''
                || ! in_array($image['type'] ?? null, ['Defective Image', 'Rejected Image'], true)) {
                throw ValidationException::withMessages([
                    'evidenceImages' => 'Every QC evidence image must have a valid file and image tag.',
                ]);
            }
        }

        $imageTypes = collect($evidenceImages)->pluck('type')->all();
        if ($quantities['defective'] > 0 && ! in_array('Defective Image', $imageTypes, true)) {
            throw ValidationException::withMessages(['evidenceImages' => 'At least one image must be tagged Defective Image when defective quantity is greater than zero.']);
        }
        if ($quantities['rejected'] > 0 && ! in_array('Rejected Image', $imageTypes, true)) {
            throw ValidationException::withMessages(['evidenceImages' => 'At least one image must be tagged Rejected Image when rejected quantity is greater than zero.']);
        }

        return $total;
    }

    /** @param array<int, array{name?: mixed, value?: mixed, result?: mixed}>|null $parameters */
    public function validateRequiredParameters(?array $parameters): void
    {
        $rows = collect($parameters)->filter(fn ($row) => is_array($row));
        $byName = $rows->keyBy(fn (array $row) => trim((string) ($row['name'] ?? '')));
        foreach (['Quantity', 'Invoice / PO Amount', 'Unit Price'] as $requiredName) {
            $row = $byName->get($requiredName);
            if (! $row || trim((string) ($row['value'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'parameters' => "{$requiredName} is a required QC parameter and must have a value.",
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{name: string, value: string, result: string}>|null  $parameters
     * @param  array<string, bool>|null  $documentsChecked
     * @param  array<int, array{path: string, type: string}>|null  $evidenceImages
     */
    public function recordResult(GateEntry $gate, string $sku, int $poQty, int $invoiceQty, array $split, ?string $qcReasons = null, ?string $holdReason = null, ?string $holdDocumentPath = null, ?array $parameters = null, ?array $documentsChecked = null, ?float $invoiceAmount = null, ?float $unitPrice = null, ?array $evidenceImages = null, ?string $evidenceImageType = null, ?int $physicalQty = null): QcResult
    {
        $evidenceImages = array_values($evidenceImages ?? []);
        $physicalReceived = $this->validateQcInput($split, $physicalQty ?? $invoiceQty, $invoiceAmount, $unitPrice, $evidenceImages, $holdReason);
        $this->validateRequiredParameters($parameters);
        $missing = max($invoiceQty - $physicalReceived, 0);

        return DB::transaction(function () use ($gate, $sku, $poQty, $invoiceQty, $split, $qcReasons, $holdReason, $holdDocumentPath, $parameters, $documentsChecked, $physicalReceived, $missing, $invoiceAmount, $unitPrice, $evidenceImages, $evidenceImageType) {
            $result = QcResult::create([
                'created_by' => auth()->id(),
                'gate_entry_id' => $gate->id,
                'sku' => $sku,
                'po_qty' => $poQty,
                'invoice_qty' => $invoiceQty,
                'physical_received' => $physicalReceived,
                'invoice_amount' => $invoiceAmount,
                'unit_price' => $unitPrice,
                'accepted_qty' => $split['accepted'],
                'qc_hold_qty' => $split['qcHold'],
                'defective_qty' => $split['defective'],
                'rejected_qty' => $split['rejected'],
                'missing_qty' => $missing,
                'qc_reasons' => $qcReasons,
                'parameters' => empty($parameters) ? null : array_values($parameters),
                'documents_checked' => $documentsChecked,
                // Keep the legacy path array for existing integrations while
                // storing each image and its classification as a QC-line child.
                'evidence_images' => collect($evidenceImages)->pluck('path')->all() ?: null,
                'evidence_image_type' => $evidenceImageType ?? ($evidenceImages[0]['type'] ?? null),
                'hold_reason' => $split['qcHold'] > 0 ? $holdReason : null,
                'hold_document_path' => $split['qcHold'] > 0 ? $holdDocumentPath : null,
                // A rejection automatically opens a purchase return for the
                // vendor to action — surfaced via NotificationCenter::vendor()
                // and the vendor dashboard's "Purchase Return" button.
                'return_status' => $split['rejected'] > 0 ? 'pending' : null,
                'return_requested_at' => $split['rejected'] > 0 ? now() : null,
            ]);

            if ($evidenceImages) {
                $result->evidenceImageRecords()->createMany(collect($evidenceImages)->map(fn (array $image) => [
                    'image_path' => $image['path'],
                    'image_type' => $image['type'],
                ])->all());
            }

            // A full rejection (nothing accepted/held/defective, everything
            // rejected) has nothing left for Store Manager to post — skip GRN
            // Check entirely and go straight to notifying the vendor.
            $fullyRejected = $split['accepted'] === 0 && $split['qcHold'] === 0 && $split['defective'] === 0 && $split['rejected'] > 0;

            $gate->update(['status' => $fullyRejected ? 'rejected' : 'qc_done']);

            $failedParams = collect($parameters)->where('result', 'Fail')->count();

            AuditLogger::log(
                'QC Check recorded',
                "{$gate->gate_no} · accepted {$split['accepted']}, hold {$split['qcHold']}, defective {$split['defective']}, rejected {$split['rejected']}"
                    .($failedParams > 0 ? " · {$failedParams} parameter(s) failed" : '')
                    .($fullyRejected ? ' · fully rejected, GRN Check skipped' : ' · sent to GRN Check'),
                $result,
            );

            if ($split['rejected'] > 0) {
                AuditLogger::log(
                    'Purchase return notification sent to vendor',
                    "{$gate->gate_no} · {$gate->vendor_name} · rejected {$split['rejected']}".($qcReasons ? " · {$qcReasons}" : ''),
                    $result,
                );
            }

            return $result;
        });
    }

    /**
     * Record a receipt with a separate QC split for every SKU on the PO.
     * The legacy parent QcResult is retained as an aggregate so existing
     * dashboards keep working, while the child rows are the source of truth
     * for GRN and inventory posting.
     *
     * @param array<int, array{sku:string, poQty:int, invoiceQty:int, physicalReceived:int, accepted:int, qcHold:int, defective:int, rejected:int, invoiceAmount:float, unitPrice:float}> $lines
     */
    public function recordMultiResult(GateEntry $gate, array $lines, ?string $qcReasons = null, ?string $holdReason = null, ?string $holdDocumentPath = null, ?array $parameters = null, ?array $documentsChecked = null, ?array $evidenceImages = null): QcResult
    {
        $evidenceImages = array_values($evidenceImages ?? []);
        $this->validateRequiredParameters($parameters);

        if ($lines === []) {
            throw ValidationException::withMessages(['lineSplits' => 'At least one SKU line is required for QC.']);
        }

        $totals = collect($lines)->reduce(function (array $carry, array $line): array {
            foreach (['poQty', 'invoiceQty', 'physicalReceived', 'accepted', 'qcHold', 'defective', 'rejected'] as $key) {
                $value = $line[$key] ?? 0;
                if (! is_numeric($value) || (int) $value < 0 || (float) $value !== (float) (int) $value) {
                    throw ValidationException::withMessages(["lineSplits.{$key}" => 'Enter whole quantities of zero or more.']);
                }
                $carry[$key] += (int) $value;
            }

            if (trim((string) ($line['sku'] ?? '')) === '') {
                throw ValidationException::withMessages(['lineSplits' => 'Every QC line must have an SKU.']);
            }

            $splitTotal = (int) $line['accepted'] + (int) $line['qcHold'] + (int) $line['defective'] + (int) $line['rejected'];
            if ($splitTotal !== (int) $line['physicalReceived']) {
                throw ValidationException::withMessages([
                    'lineSplits' => "QC split for {$line['sku']} must equal its physical quantity ({$line['physicalReceived']}).",
                ]);
            }

            if (! is_numeric($line['invoiceAmount'] ?? null) || (float) $line['invoiceAmount'] < 0
                || ! is_numeric($line['unitPrice'] ?? null) || (float) $line['unitPrice'] < 0) {
                throw ValidationException::withMessages(['lineSplits' => "Invoice amount and unit price are required for {$line['sku']}."]); 
            }

            return $carry;
        }, array_fill_keys(['poQty', 'invoiceQty', 'physicalReceived', 'accepted', 'qcHold', 'defective', 'rejected'], 0));

        if ($totals['qcHold'] > 0 && trim((string) $holdReason) === '') {
            throw ValidationException::withMessages(['holdReason' => 'Add a comment explaining the QC hold before sending these lines to GRN Check.']);
        }

        $evidenceRequired = $totals['defective'] > 0 || $totals['rejected'] > 0;
        if ($evidenceRequired && count($evidenceImages) < 2) {
            throw ValidationException::withMessages(['evidenceImages' => 'At least 2 images are required when any SKU is defective or rejected.']);
        }

        $imageTypes = collect($evidenceImages)->pluck('type')->all();
        foreach ($evidenceImages as $image) {
            if (! is_array($image) || trim((string) ($image['path'] ?? '')) === ''
                || ! in_array($image['type'] ?? null, ['Defective Image', 'Rejected Image'], true)) {
                throw ValidationException::withMessages(['evidenceImages' => 'Every QC evidence image must have a valid file and image tag.']);
            }
        }
        if ($totals['defective'] > 0 && ! in_array('Defective Image', $imageTypes, true)) {
            throw ValidationException::withMessages(['evidenceImages' => 'At least one image must be tagged Defective Image.']);
        }
        if ($totals['rejected'] > 0 && ! in_array('Rejected Image', $imageTypes, true)) {
            throw ValidationException::withMessages(['evidenceImages' => 'At least one image must be tagged Rejected Image.']);
        }

        return DB::transaction(function () use ($gate, $lines, $totals, $qcReasons, $holdReason, $holdDocumentPath, $parameters, $documentsChecked, $evidenceImages): QcResult {
            $missing = collect($lines)->sum(fn (array $line) => max((int) $line['invoiceQty'] - (int) $line['physicalReceived'], 0));
            $result = QcResult::create([
                'created_by' => auth()->id(),
                'gate_entry_id' => $gate->id,
                'sku' => count($lines) > 1 ? 'MULTI-SKU' : $lines[0]['sku'],
                'po_qty' => $totals['poQty'],
                'invoice_qty' => $totals['invoiceQty'],
                'physical_received' => $totals['physicalReceived'],
                'accepted_qty' => $totals['accepted'],
                'qc_hold_qty' => $totals['qcHold'],
                'defective_qty' => $totals['defective'],
                'rejected_qty' => $totals['rejected'],
                'missing_qty' => $missing,
                'qc_reasons' => $qcReasons,
                'parameters' => empty($parameters) ? null : array_values($parameters),
                'documents_checked' => $documentsChecked,
                'evidence_images' => collect($evidenceImages)->pluck('path')->all() ?: null,
                'evidence_image_type' => $evidenceImages[0]['type'] ?? null,
                'hold_reason' => $totals['qcHold'] > 0 ? $holdReason : null,
                'hold_document_path' => $totals['qcHold'] > 0 ? $holdDocumentPath : null,
                'return_status' => $totals['rejected'] > 0 ? 'pending' : null,
                'return_requested_at' => $totals['rejected'] > 0 ? now() : null,
            ]);

            foreach ($lines as $line) {
                $result->lines()->create([
                    'gate_entry_id' => $gate->id,
                    'sku' => $line['sku'],
                    'po_qty' => $line['poQty'],
                    'invoice_qty' => $line['invoiceQty'],
                    'physical_received' => $line['physicalReceived'],
                    'accepted_qty' => $line['accepted'],
                    'qc_hold_qty' => $line['qcHold'],
                    'defective_qty' => $line['defective'],
                    'rejected_qty' => $line['rejected'],
                    'missing_qty' => max((int) $line['invoiceQty'] - (int) $line['physicalReceived'], 0),
                    'invoice_amount' => $line['invoiceAmount'],
                    'unit_price' => $line['unitPrice'],
                    'qc_reasons' => $qcReasons,
                    'hold_reason' => $line['qcHold'] > 0 ? $holdReason : null,
                    'hold_document_path' => $line['qcHold'] > 0 ? $holdDocumentPath : null,
                    'parameters' => empty($parameters) ? null : array_values($parameters),
                    'documents_checked' => $documentsChecked,
                    'evidence_images' => $evidenceImages ? collect($evidenceImages)->pluck('path')->all() : null,
                ]);
            }

            $gate->update(['status' => 'qc_done']);
            AuditLogger::log('Multi-SKU QC Check recorded', "{$gate->gate_no} · ".count($lines).' SKU lines · accepted '.$totals['accepted'].', hold '.$totals['qcHold'].', defective '.$totals['defective'].', rejected '.$totals['rejected'], $result);

            return $result;
        });
    }
}
