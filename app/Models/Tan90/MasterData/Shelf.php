<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shelf extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_shelves';

    protected $fillable = ['code', 'name', 'tan90_rack_id', 'capacity', 'status', 'approval_status'];

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class, 'tan90_rack_id');
    }

    public function bins(): HasMany
    {
        return $this->hasMany(Bin::class, 'tan90_shelf_id');
    }
}
