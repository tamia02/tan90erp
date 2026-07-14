<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Machine extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_machines';

    protected $fillable = [
        'code', 'name', 'tan90_plant_id', 'machine_type', 'manufacturer', 'model',
        'serial_no', 'commissioned', 'capacity', 'maintenance_cycle', 'iot_enabled',
        'status', 'approval_status',
    ];

    protected $casts = ['commissioned' => 'date'];

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'tan90_plant_id');
    }
}
