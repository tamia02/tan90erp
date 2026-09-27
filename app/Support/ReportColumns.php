<?php

namespace App\Support;

use App\Models\GateEntry;
use App\Models\GrnRecord;
use Illuminate\Support\Arr;

class ReportColumns
{
    /** Stable, allow-listed column definitions shared by GRN screen and exports. */
    public const GRN = [
        'sku' => 'SKU / Material', 'po_qty' => 'PO Qty', 'physical_received' => 'Physical Received',
        'accepted_qty' => 'Accepted', 'rejected_qty' => 'Rejected', 'defective_qty' => 'Defective',
        'qc_hold_qty' => 'On Hold', 'missing_qty' => 'Missing', 'unit_price' => 'Unit Price',
        'invoice_amount' => 'Invoice Value', 'accepted_value' => 'Accepted Value', 'suggested_bin' => 'Suggested Bin',
    ];

    public static function selectedGrn(mixed $requested): array
    {
        $values = is_array($requested) ? $requested : explode(',', (string) $requested);
        $selected = array_values(array_intersect(array_keys(self::GRN), Arr::where($values, fn ($v) => is_string($v))));
        return $selected ?: array_keys(self::GRN);
    }

    public static function grnRows(GateEntry $gate, GrnRecord $grn): array
    {
        if ($grn->relationLoaded('lines') && $grn->lines->isNotEmpty()) {
            return $grn->lines->map(fn ($line) => collect(self::GRN)->mapWithKeys(fn ($label, $key) => [$key => $line->{$key}])->all())->all();
        }

        return [collect(self::GRN)->mapWithKeys(fn ($label, $key) => [$key => match ($key) {
            'sku' => $grn->sku ?: $gate->material,
            'unit_price' => $grn->qcResult?->unit_price,
            'invoice_amount' => $grn->qcResult?->invoice_amount,
            'accepted_value' => $grn->qcResult?->unit_price !== null ? (int) $grn->accepted_qty * (float) $grn->qcResult->unit_price : null,
            default => $grn->{$key},
        }])->all()];
    }
}
