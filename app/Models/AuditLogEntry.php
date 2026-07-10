<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Append-only — nothing ever updates or deletes a row here. Replaces the
// React prototype's describeAction()-wrapped reducer; see AuditLogger.
#[Fillable(['action', 'detail'])]
class AuditLogEntry extends Model
{
    //
}
