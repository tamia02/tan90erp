<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['gate_entry_id', 'box_count', 'packing_unit', 'staging_area', 'manpower_count', 'unloaded_by', 'pod_lr_ref', 'pod_document_path', 'allotted_at', 'started_at', 'completed_at'])]
class UnloadingRecord extends Model
{
    use \App\Models\Concerns\SiteScopedViaParent;

    protected function casts(): array
    {
        return [
            'allotted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
