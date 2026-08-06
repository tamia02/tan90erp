<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $table = 'flow_shipments';

    protected $fillable = [
        'shipment_number', 'warehouse', 'dock_number', 'transporter', 'vehicle_number', 'driver_name',
        'temperature_logger_id', 'seal_number', 'status', 'released_by', 'released_at',
    ];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function handlingUnits(): HasMany
    {
        return $this->hasMany(HandlingUnit::class, 'shipment_id');
    }

    public function temperatureEvents(): HasMany
    {
        return $this->hasMany(TemperatureEvent::class, 'shipment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'shipment_id');
    }

    public function hasOpenExcursion(): bool
    {
        return $this->temperatureEvents()->where('excursion', true)->whereNull('disposition')->exists();
    }
}
