<?php

namespace App\Models\Forge;

use App\Models\Tan90\MasterData\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Freezer extends Model
{
    protected $table = 'forge_freezers';

    protected $fillable = ['code', 'name', 'plant', 'site_id', 'capacity', 'threshold_temp_min', 'threshold_temp_max', 'state', 'status'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'site_id');
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'decimal:3',
            'threshold_temp_min' => 'decimal:2',
            'threshold_temp_max' => 'decimal:2',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FreezerLog::class, 'freezer_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(FreezerReading::class, 'freezer_id');
    }

    public function openLog(): ?FreezerLog
    {
        return $this->logs()->whereNull('ended_at')->latest('started_at')->first();
    }

    public function latestReading(): ?FreezerReading
    {
        return $this->readings()->latest('recorded_at')->first();
    }

    public function isOutOfRange(float $temperature): bool
    {
        return $temperature < (float) $this->threshold_temp_min || $temperature > (float) $this->threshold_temp_max;
    }
}
