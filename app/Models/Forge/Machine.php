<?php

namespace App\Models\Forge;

use App\Models\Tan90\BomRecipeCosting\WorkCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    protected $table = 'forge_machines';

    protected $fillable = ['work_center_id', 'code', 'name', 'plant', 'state', 'status'];

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }

    public function downtimeEvents(): HasMany
    {
        return $this->hasMany(MachineDowntimeEvent::class, 'machine_id');
    }

    public function jobCards(): HasMany
    {
        return $this->hasMany(JobCard::class, 'machine_id');
    }

    public function openDowntimeEvent(): ?MachineDowntimeEvent
    {
        return $this->downtimeEvents()->whereNull('ended_at')->latest('started_at')->first();
    }
}
