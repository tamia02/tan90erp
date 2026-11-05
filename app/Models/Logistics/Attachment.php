<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'logistics_attachments';

    protected $guarded = [];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
