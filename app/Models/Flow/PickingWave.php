<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickingWave extends Model
{
    protected $table = 'flow_picking_waves';

    protected $fillable = ['wave_number', 'warehouse', 'status', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function pickTasks(): HasMany
    {
        return $this->hasMany(PickTask::class, 'wave_id');
    }
}
