<?php

namespace App\Models\Forge;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deviation extends Model
{
    protected $table = 'forge_deviations';

    protected $fillable = [
        'work_order_id', 'source_type', 'description', 'qty', 'uom', 'containment', 'root_cause',
        'disposition', 'capa_action', 'capa_owner_id', 'capa_target_date',
        'effectiveness_check', 'status', 'opened_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'capa_target_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function capaOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capa_owner_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function reworkWorkOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class, 'source_deviation_id');
    }
}
