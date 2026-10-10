<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Services\Tan90\MasterData\NumberSeriesService;

// Next PO number in the client's Zoho format: PO + type code + financial year
// + 4-digit running number, e.g. POTG26270147. The running number continues
// from the highest PO of that type and year already in the ERP (Zoho-pulled
// POs included), so ERP and Zoho numbers never restart or clash.
class PoNumberService
{
    // Nature of PO decides the code; "Others" falls back to the PO category.
    private const NATURE_CODES = ['Trading' => 'TG', 'Raw Material' => 'RM', 'PM Packaging Material' => 'PM'];

    private const CATEGORY_CODES = ['RM' => 'RM', 'PM' => 'PM', 'Consumables' => 'CS', 'Capex' => 'CA', 'FG' => 'FG', 'Other' => 'OT'];

    public function code(?string $nature, ?string $category = null): ?string
    {
        return self::NATURE_CODES[$nature ?? ''] ?? ($nature ? (self::CATEGORY_CODES[$category ?? ''] ?? 'OT') : null);
    }

    public function next(?string $nature, ?string $category = null, ?\DateTimeInterface $date = null): ?string
    {
        $code = $this->code($nature, $category);
        if ($code === null) {
            return null;
        }
        $prefix = 'PO'.$code.NumberSeriesService::financialYear($date);

        // Older ERP numbers were written with spaces ("PO RM 2627 0020").
        $max = PurchaseOrder::query()
            ->whereRaw("REPLACE(po_number, ' ', '') LIKE ?", [$prefix.'%'])
            ->pluck('po_number')
            ->map(fn ($n) => substr(str_replace(' ', '', $n), strlen($prefix)))
            ->filter(fn ($rest) => ctype_digit($rest) && strlen($rest) <= 5)
            ->map(fn ($rest) => (int) $rest)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
