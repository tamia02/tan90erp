<?php

namespace App\Support;

/**
 * Every gate-entry status badge across the app used to just title-case the
 * raw enum value (ucfirst/str_replace or CSS text-transform: capitalize).
 * That reads fine for most statuses (dock_assigned -> "Dock Assigned") but
 * produces "Grn" for the one status whose raw name isn't self-explanatory
 * -- confirmed live on Store Exec's Unloading Desk history. The status
 * filter dropdowns already had the correct "Ready for QC" label hardcoded
 * in one place; this centralizes it so every badge matches.
 */
class GateStatusLabels
{
    private const LABELS = [
        'pending_validation' => 'Pending Validation',
        'validated' => 'Validated',
        'dock_assigned' => 'Dock Assigned',
        'allotted' => 'Allotted',
        'unloading' => 'Unloading',
        'grn' => 'Ready for QC',
        'qc_done' => 'QC Done',
        'grn_posted' => 'GRN Posted, Awaiting Putaway',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        'loaded' => 'Loaded, Ready to Exit',
        'dispatched' => 'Dispatched',
        'checked_in' => 'Visitor Checked In',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }
}
