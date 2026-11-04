<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['qc_result_id', 'image_path', 'image_type'])]
class QcEvidenceImage extends Model
{
    protected $table = 'qc_result_evidence_images';

    /** @return BelongsTo<QcResult, $this> */
    public function qcResult(): BelongsTo
    {
        return $this->belongsTo(QcResult::class);
    }
}
