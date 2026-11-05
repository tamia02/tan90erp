<?php

namespace App\Support;

use App\Models\Tan90\MasterData\Uom;

// One list of units for every item form, read from Master Data > Units, so
// nobody types "KGS" for "KG". Admin adds new units there.
class Units
{
    /** @return array<int, string> */
    public static function options(): array
    {
        $codes = Uom::where('status', 'active')->orderBy('code')->pluck('code')->all();

        return $codes ?: ['BOX', 'KG', 'LTR', 'NOS', 'PCS'];
    }
}
