<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class Transporter extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_transporters';

    protected $fillable = [
        'code', 'name', 'gstin', 'service_type', 'contact', 'phone',
        'email', 'gps_tracking', 'insurance', 'status', 'approval_status',
    ];
}
