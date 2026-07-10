<?php

namespace App\Services;

use App\Models\AuditLogEntry;

// Replaces the React prototype's describeAction()-wrapped reducer — every
// meaningful action across every service leaves one append-only row here.
class AuditLogger
{
    public static function log(string $action, string $detail = ''): void
    {
        AuditLogEntry::create(['action' => $action, 'detail' => $detail]);
    }
}
