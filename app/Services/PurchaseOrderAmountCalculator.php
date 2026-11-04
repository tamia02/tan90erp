<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;

/**
 * Calculates net and tax-inclusive amounts for a PO line allocation. The
 * vendor submission, gate validation, and finance match must agree on this
 * calculation or a valid GST-inclusive invoice can be blocked downstream.
 */
class PurchaseOrderAmountCalculator
{
    /**
     * Resolve a shipment's product_lines against PO lines. Returns null when
     * a multi-line shipment cannot be unambiguously allocated.
     *
     * @param  array<int, array<string, mixed>>  $productLines
     * @return array<int, float>|null PO line id => shipped quantity
     */
    public function allocations(PurchaseOrder $po, array $productLines, ?float $fallbackQuantity = null): ?array
    {
        $lines = $po->lines->keyBy('id');
        if ($lines->isEmpty()) {
            return null;
        }

        if ($productLines === []) {
            return $lines->count() === 1 && $fallbackQuantity !== null
                ? [(int) $lines->first()->id => $fallbackQuantity]
                : null;
        }

        $allocations = [];
        foreach ($productLines as $productLine) {
            if (! is_array($productLine) || ! is_numeric($productLine['qty'] ?? null)) {
                return null;
            }

            $quantity = (float) $productLine['qty'];
            if ($quantity < 0) {
                return null;
            }
            if ($quantity === 0.0) {
                continue;
            }

            $line = null;
            $lineId = $productLine['po_line_id'] ?? null;
            if (is_numeric($lineId)) {
                $line = $lines->get((int) $lineId);
            }

            if (! $line) {
                $sku = trim((string) ($productLine['sku'] ?? $productLine['name'] ?? ''));
                if ($sku === '') {
                    return null;
                }

                $matches = $lines->filter(fn (PurchaseOrderLine $candidate) => mb_strtolower(trim((string) $candidate->product)) === mb_strtolower($sku));
                if ($matches->count() !== 1) {
                    return null;
                }
                $line = $matches->first();
            }

            $allocations[(int) $line->id] = ($allocations[(int) $line->id] ?? 0) + $quantity;
        }

        return $allocations !== [] ? $allocations : null;
    }

    /**
     * @param  array<int, float>  $allocations  PO line id => allocated quantity
     * @return array{net: float, tax: float, total: float}
     */
    public function amounts(PurchaseOrder $po, array $allocations): array
    {
        $lines = $po->lines->keyBy('id');
        $orderedSubtotal = 0.0;
        $allocatedSubtotal = 0.0;
        $allocatedLineTax = 0.0;
        $allocatedVolume = 0.0;
        $orderedVolume = 0.0;

        foreach ($lines as $line) {
            $quantity = (float) $line->quantity;
            $orderedVolume += $quantity;
            $lineNet = ($quantity * (float) $line->list_price) - (float) ($line->discount ?? 0);
            $orderedSubtotal += $lineNet;

            $allocated = (float) ($allocations[(int) $line->id] ?? 0);
            if ($allocated <= 0 || $quantity <= 0) {
                continue;
            }

            $ratio = $allocated / $quantity;
            $allocatedSubtotal += $lineNet * $ratio;
            $allocatedLineTax += (float) ($line->tax ?? 0) * $ratio;
            $allocatedVolume += $allocated;
        }

        $basis = $orderedSubtotal > 0
            ? $allocatedSubtotal / $orderedSubtotal
            : ($orderedVolume > 0 ? $allocatedVolume / $orderedVolume : 0.0);
        $net = $allocatedSubtotal - ((float) ($po->discount ?? 0) * $basis);
        $tax = $po->reverse_charge
            ? 0.0
            : $allocatedLineTax + ((float) ($po->tax ?? 0) * $basis);
        $total = $net + $tax + ((float) ($po->adjustment ?? 0) * $basis);

        return ['net' => round($net, 2), 'tax' => round($tax, 2), 'total' => round($total, 2)];
    }

    /**
     * @param  array<int, float>  $quantities  PO line id => allocated quantity
     * @return array<int, float>|null
     */
    public function addAllocations(?array $current, PurchaseOrder $po, array $productLines, ?float $fallbackQuantity): ?array
    {
        $allocated = $this->allocations($po, $productLines, $fallbackQuantity);
        if ($allocated === null) {
            return null;
        }

        foreach ($allocated as $lineId => $quantity) {
            $current[$lineId] = ($current[$lineId] ?? 0.0) + $quantity;
        }

        return $current;
    }
}
