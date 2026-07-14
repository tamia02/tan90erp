<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bin extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_bins';

    protected $fillable = ['code', 'name', 'tan90_shelf_id', 'capacity', 'occupancy', 'temperature', 'status', 'approval_status'];

    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'tan90_shelf_id');
    }
}
