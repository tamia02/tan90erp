<?php

namespace App\Models\Forge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreezerReading extends Model
{
    protected $table = 'forge_freezer_readings';

    protected $fillable = ['freezer_id', 'temperature', 'humidity', 'is_alert', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'humidity' => 'decimal:2',
            'is_alert' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(Freezer::class, 'freezer_id');
    }
}
