<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemperatureEvent extends Model
{
    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    protected $table = 'flow_temperature_events';

    protected $fillable = ['shipment_id', 'reading_celsius', 'excursion', 'disposition', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'excursion' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
