<?php

namespace App\Services\Outward;

use App\Models\Inventory\InventoryItem;
use App\Models\SkuMaster;
use App\Services\PurchaseOrderTaxService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Calculates a tax snapshot for each outward line from its inventory GST % and supply states. */
class OutwardTaxService
{
    public function __construct(private readonly PurchaseOrderTaxService $states) {}

    /**
     * @param  array<int, array{item_id:int,qty:float|string,rate:float|string}>  $lines
     * @param  Collection<int, InventoryItem>  $items
     * @return array{lines: array<int, array<string, mixed>>, subtotal_value: float, tax_total: float, total_value: float, cgst: float, sgst: float, igst: float, needs_state: bool}
     */
    public function calculate(
        array $lines,
        Collection $items,
        bool $taxable,
        ?string $fromGstin,
        ?string $toGstin,
        ?string $fromState,
        ?string $toState,
        bool $requireStates = false,
    ): array {
        $savedLines = [];
        $subtotal = $taxTotal = $cgstTotal = $sgstTotal = $igstTotal = 0.0;
        $needsState = false;

        foreach ($lines as $index => $line) {
            $item = $items->get((int) $line['item_id']);
            $qty = (float) $line['qty'];
            $rate = (float) ($line['rate'] ?? 0);
            if ($qty <= 0 || $rate < 0) {
                throw ValidationException::withMessages(["lines.{$index}.rate" => 'Quantity must be positive and rate cannot be negative.']);
            }

            // Keep the selected line rate in the tax snapshot. The form
            // prefills it from the SKU master (or 0% for internal transfers),
            // while service callers that omit it retain the master default.
            // Delivery challans store the rate for reference and never charge tax.
            $gstRate = $taxable
                ? (array_key_exists('gst_rate', $line) ? $this->providedGstRate($line['gst_rate'], $index) : $this->gstRate($item))
                : $this->challanGstRate($line, $index);
            $value = round($qty * $rate, 2);
            $intraState = $this->sameState($fromGstin, $toGstin, $fromState, $toState);
            $missingState = $taxable && $gstRate > 0 && $intraState === null;
            $needsState = $needsState || $missingState;

            if ($missingState && $requireStates) {
                throw ValidationException::withMessages([
                    'toState' => 'Enter the receiver state to calculate this item’s GST correctly.',
                ]);
            }

            $cgstRate = $taxable && $intraState === true ? $gstRate / 2 : 0.0;
            $sgstRate = $cgstRate;
            $igstRate = $taxable && $intraState === false ? $gstRate : 0.0;
            $tax = $taxable && ! $missingState ? round($value * $gstRate / 100, 2) : 0.0;
            $cgst = $cgstRate > 0 ? round($tax / 2, 2) : 0.0;
            $sgst = $sgstRate > 0 ? round($tax - $cgst, 2) : 0.0;
            $igst = $igstRate > 0 ? $tax : 0.0;
            $taxType = $cgstRate > 0 ? 'CGST + SGST' : ($igstRate > 0 ? 'IGST' : null);

            $savedLines[] = [
                'item_id' => (int) $line['item_id'],
                'qty' => $qty,
                'rate' => $rate,
                'value' => $value,
                'gst_rate' => $gstRate,
                'tax_type' => $taxType,
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $igstRate,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'igst_amount' => $igst,
                'tax_amount' => $tax,
                'total_amount' => round($value + $tax, 2),
            ];

            $subtotal += $value;
            $taxTotal += $tax;
            $cgstTotal += $cgst;
            $sgstTotal += $sgst;
            $igstTotal += $igst;
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);

        return [
            'lines' => $savedLines,
            'subtotal_value' => $subtotal,
            'tax_total' => $taxTotal,
            'total_value' => round($subtotal + $taxTotal, 2),
            'cgst' => round($cgstTotal, 2),
            'sgst' => round($sgstTotal, 2),
            'igst' => round($igstTotal, 2),
            'needs_state' => $needsState,
        ];
    }

    private function gstRate(InventoryItem $item): float
    {
        $raw = $item->custom_fields['gst'] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            $skuMaster = SkuMaster::query()->where('sku', $item->sku)->first(['tax', 'taxable', 'zoho_hsn']);

            if ($skuMaster && $skuMaster->taxable === false) {
                return 0.0;
            }

            // Inventory item GST details are saved on SKU Master by the item
            // editor. Use that explicit item rate before the HSN fallback;
            // otherwise outward invoices silently showed 0% despite a rate
            // already being configured for the SKU.
            $raw = $skuMaster?->tax;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $raw = $this->states->rateForHsn($skuMaster?->zoho_hsn) ?? 0;
            }
        }
        $rate = is_string($raw) ? trim(str_replace('%', '', $raw)) : $raw;
        if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            throw ValidationException::withMessages([
                'lines' => "{$item->displayName()} has an invalid GST % in its item custom fields.",
            ]);
        }

        return round((float) $rate, 3);
    }

    public function defaultGstRate(InventoryItem $item): float
    {
        return $this->gstRate($item);
    }

    /** @param array<string, mixed> $line */
    private function challanGstRate(array $line, int|string $index): float
    {
        return $this->providedGstRate($line['gst_rate'] ?? 0, $index);
    }

    private function providedGstRate(mixed $raw, int|string $index): float
    {
        $rate = is_string($raw) ? trim(str_replace('%', '', $raw)) : $raw;
        if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            throw ValidationException::withMessages([
                "lines.{$index}.gst_rate" => 'GST % must be between 0 and 100.',
            ]);
        }

        return round((float) $rate, 3);
    }

    private function sameState(?string $fromGstin, ?string $toGstin, ?string $fromState, ?string $toState): ?bool
    {
        $from = $this->stateCode($fromGstin, $fromState);
        $to = $this->stateCode($toGstin, $toState);

        return $from && $to ? $from === $to : null;
    }

    private function stateCode(?string $gstin, ?string $state): ?string
    {
        $prefix = substr(strtoupper(preg_replace('/\s+/', '', (string) $gstin)), 0, 2);
        if (preg_match('/^\d{2}$/', $prefix)) {
            return $prefix;
        }

        return $this->states->stateCode($state);
    }
}

