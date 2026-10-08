<?php

namespace App\Services\Outward;

// The client's GST outward decision tree, step by step:
//
// Sales Order -> Tax Invoice
//     B2B (customer has GSTIN; turnover > Rs 5 Cr) -> e-Invoice IRN -> E-Way Bill
//     B2C (no IRN)                                  -> E-Way Bill only above the threshold
// Transfer Order / Internal Movement / Job Work -> are sender and receiver GSTINs identical?
//     NO  (different GSTINs / inter-state branch) -> Tax Invoice (B2B supply) -> IRN -> E-Way Bill
//     YES (same GSTIN / non-taxable movement)     -> Delivery Challan
//          -> E-Way Bill if consignment > Rs 50,000 OR inter-state job work, else print challan only
//
// Returns the documents required plus the path taken, in plain words.
class OutwardComplianceService
{
    /**
     * @return array{document_type: string, is_b2b: bool, requires_irn: bool, requires_ewb: bool, steps: array<int, string>}
     */
    public function decide(string $purpose, ?string $fromGstin, ?string $toGstin, ?string $fromState, ?string $toState, float $value): array
    {
        $fromGstin = $this->clean($fromGstin);
        $toGstin = $this->clean($toGstin);
        $threshold = (float) config('outward.ewb_threshold', 50000);
        $einvoice = (bool) config('outward.einvoice_applicable', true);
        $money = 'Rs '.number_format($value, 2);
        $limit = 'Rs '.number_format($threshold, 0);
        $steps = [];

        if ($purpose === 'sales') {
            $steps[] = 'Purpose: Sales Order -> generate a Tax Invoice.';
            if ($toGstin) {
                $steps[] = "Customer has a GSTIN ({$toGstin}) -> B2B supply.";
                $steps[] = $einvoice ? 'B2B with turnover above Rs 5 Cr -> e-Invoice IRN required (from the IRP portal).' : 'e-Invoicing is switched off in settings -> no IRN.';
                $steps[] = 'B2B -> E-Way Bill required (generated after the IRN).';

                return $this->result('tax_invoice', true, $einvoice, true, $steps);
            }
            $steps[] = 'Customer has no GSTIN -> B2C supply, no IRN needed.';
            $needsEwb = $value > $threshold;
            $steps[] = "Check E-Way Bill threshold: consignment {$money} ".($needsEwb ? "is above {$limit} -> E-Way Bill required." : "is not above {$limit} -> no E-Way Bill.");

            return $this->result('tax_invoice', false, false, $needsEwb, $steps);
        }

        $steps[] = 'Purpose: '.($purpose === 'job_work' ? 'Job Work' : 'Transfer Order / Internal Movement').' -> are sender and receiver GSTINs identical?';
        $same = $fromGstin !== null && $toGstin !== null && $fromGstin === $toGstin;

        if (! $same) {
            $steps[] = 'No - '.($toGstin ? "different GSTINs ({$fromGstin} -> {$toGstin})" : 'receiver GSTIN differs / not given').' -> treated as a B2B supply: generate a Tax Invoice.';
            $steps[] = $einvoice ? 'B2B supply -> e-Invoice IRN required (from the IRP portal).' : 'e-Invoicing is switched off in settings -> no IRN.';
            $steps[] = 'Then E-Way Bill required (NIC portal).';

            return $this->result('tax_invoice', true, $einvoice, true, $steps);
        }

        $steps[] = "Yes - same GSTIN ({$fromGstin}) / non-taxable movement -> generate a Delivery Challan.";
        $interstate = $this->interstate($fromGstin, $toGstin, $fromState, $toState);
        $overThreshold = $value > $threshold;
        $interstateJobWork = $purpose === 'job_work' && $interstate;
        $needsEwb = $overThreshold || $interstateJobWork;
        $steps[] = "Consignment {$money} ".($overThreshold ? "is above {$limit}" : "is not above {$limit}")
            .($purpose === 'job_work' ? ($interstate ? '; this is inter-state job work' : '; job work within the state') : '')
            .($needsEwb ? ' -> E-Way Bill required.' : ' -> print the challan only, no E-Way Bill.');

        return $this->result('delivery_challan', false, false, $needsEwb, $steps);
    }

    private function result(string $document, bool $b2b, bool $irn, bool $ewb, array $steps): array
    {
        return ['document_type' => $document, 'is_b2b' => $b2b, 'requires_irn' => $irn, 'requires_ewb' => $ewb, 'steps' => $steps];
    }

    private function clean(?string $gstin): ?string
    {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) $gstin));

        return $gstin !== '' ? $gstin : null;
    }

    // Different state = inter-state. GSTIN's first two digits are the state code.
    private function interstate(?string $fromGstin, ?string $toGstin, ?string $fromState, ?string $toState): bool
    {
        if ($fromGstin && $toGstin) {
            return substr($fromGstin, 0, 2) !== substr($toGstin, 0, 2);
        }

        return filled($fromState) && filled($toState) && mb_strtolower(trim($fromState)) !== mb_strtolower(trim($toState));
    }
}
