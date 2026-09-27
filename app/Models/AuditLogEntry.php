<?php

namespace App\Models;

use App\Enums\Role;
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

    /** The vendor this entry belongs to, if any — used to scope a vendor's own activity feed and to block cross-vendor access to a detail page. */
    public function vendorName(): ?string
    {
        $subject = $this->subject;

        if (! $subject) {
            return null;
        }

        if ($subject instanceof User) {
            return $subject->role === Role::Vendor ? $subject->name : null;
        }

        if (isset($subject->vendor_name)) {
            return $subject->vendor_name;
        }

        if (method_exists($subject, 'gateEntry')) {
            return $subject->gateEntry?->vendor_name;
        }

        return null;
    }
}
