<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class HsnTaxRule extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_hsn_tax_rules';

    protected $fillable = [
        'hsn', 'description', 'gst_rate', 'cess', 'input_credit',
        'effective_from', 'status', 'approval_status',
    ];

    protected $casts = ['effective_from' => 'date'];

    public static function criticalFields(): array
    {
        return ['gst_rate', 'input_credit'];
    }
}
