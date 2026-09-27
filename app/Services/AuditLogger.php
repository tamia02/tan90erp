<?php

namespace App\Services;

use App\Models\AuditLogEntry;
use Illuminate\Database\Eloquent\Model;

// Replaces the React prototype's describeAction()-wrapped reducer — every
// meaningful action across every service leaves one append-only row here.
class AuditLogger
{
    /** Pass the record the action was about as $subject so activity feeds can link through to the full submitted data, not just $detail's one-line summary. */
    public static function log(string $action, string $detail = '', ?Model $subject = null): void
    {
        AuditLogEntry::create([
            'action' => $action,
            'detail' => $detail,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }
}
