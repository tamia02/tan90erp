<?php

namespace App\Services;

use App\Models\PurchaseOrder;

// Taxable value and GST shown separately (client meeting 2026-10-13): CGST +
// SGST inside the company's state, IGST otherwise, then the total. Uses the
// same per-line rates and place of supply as the PO PDF and tax totals.
class AmountBreakdown
{
    public function __construct(private PurchaseOrderTaxService $tax) {}

    /**
     * @param  array<int, float>|null  $quantities  PO line id => quantity (null = the whole PO)
     * @return array{taxable: float, cgst: float, sgst: float, igst: float, gst: float, total: float, reverse_charge: bool}
     */
    public function forPo(PurchaseOrder $po, ?array $quantities = null): array
    {
        $po->loadMissing('lines');
        $sum = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
        foreach ($po->lines as $line) {
            $ordered = (float) $line->quantity;
            $qty = $quantities === null ? $ordered : (float) ($quantities[$line->id] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $share = $ordered > 0 ? $qty / $ordered : 1.0;
            $rate = (float) ($line->gst_rate ?? $this->tax->rateForHsn($line->hsn_sac) ?? 0);
            $placeOfSupply = $this->tax->placeOfSupply($po->shipping_state, $po->billing_state);
            $b = $this->tax->breakdown($qty * (float) $line->list_price, (float) ($line->discount ?? 0) * $share, $rate, $placeOfSupply, (bool) $po->reverse_charge);
            foreach (['taxable', 'cgst', 'sgst', 'igst'] as $k) {
                $sum[$k] += $b[$k];
            }
        }

        return $this->finish($sum, (bool) $po->reverse_charge);
    }

    /**
     * A known tax-inclusive amount (e.g. a vendor invoice) split the way its PO lines are taxed.
     *
     * @param  array<int, float>|null  $quantities
     */
    public function splitTotal(float $total, PurchaseOrder $po, ?array $quantities = null): array
    {
        $shape = $this->forPo($po, $quantities);
        if ($shape['total'] <= 0) {
            return $this->finish(['taxable' => $total, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0], (bool) $po->reverse_charge);
        }
        $f = $total / $shape['total'];

        return $this->finish([
            'taxable' => $shape['taxable'] * $f, 'cgst' => $shape['cgst'] * $f,
            'sgst' => $shape['sgst'] * $f, 'igst' => $shape['igst'] * $f,
        ], (bool) $po->reverse_charge);
    }

    private function finish(array $sum, bool $reverseCharge): array
    {
        $sum = array_map(fn ($v) => round($v, 2), $sum);
        $gst = round($sum['cgst'] + $sum['sgst'] + $sum['igst'], 2);

        return $sum + ['gst' => $gst, 'total' => round($sum['taxable'] + $gst, 2), 'reverse_charge' => $reverseCharge];
    }
}
