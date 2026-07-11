<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Append-only — nothing ever updates or deletes a row here. Replaces the
// React prototype's describeAction()-wrapped reducer; see AuditLogger.
#[Fillable(['action', 'detail', 'subject_type', 'subject_id'])]
class AuditLogEntry extends Model
{
    /** The full record the action was about, when one was passed to AuditLogger::log() — lets an activity row open the whole submitted form instead of just the one-line summary. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
