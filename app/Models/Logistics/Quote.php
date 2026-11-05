<?php

namespace App\Models\Logistics;

use App\Models\Tan90\MasterData\Transporter;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $table = 'logistics_quotes';

    protected $guarded = [];

    public function transporter()
    {
        return $this->belongsTo(Transporter::class);
    }
}
