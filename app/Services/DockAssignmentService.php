<?php

namespace App\Services;

use App\Models\GateEntry;

/**
 * Central dock allocator for both gate directions.
 *
 * A dock is selected in round-robin order from the last assignment for that
 * direction, while occupied docks are skipped. The caller can still pass a
 * manager-selected dock when an operational override is needed.
 */
class DockAssignmentService
{
    public function choose(GateEntry $gate, array $docks, ?string $preferred = null): ?string
    {
        $docks = array_values(array_unique(array_filter(array_map('strval', $docks))));
        if ($docks === []) {
            return null;
        }

        $available = $this->available($gate, $docks);
        if ($preferred && in_array($preferred, $available, true)) {
            return $preferred;
        }

        if ($available === []) {
            return null;
        }

        $lastDock = GateEntry::query()
            ->where('entry_type', $gate->entry_type)
            ->whereIn('loading_dock', $docks)
            ->whereNotNull('dock_assigned_at')
            ->latest('dock_assigned_at')
            ->value('loading_dock');

        $start = $lastDock && in_array($lastDock, $docks, true)
            ? (array_search($lastDock, $docks, true) + 1) % count($docks)
            : 0;

        for ($offset = 0; $offset < count($docks); $offset++) {
            $candidate = $docks[($start + $offset) % count($docks)];
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $available[0];
    }

    public function available(GateEntry $gate, array $docks): array
    {
        $activeStatuses = $gate->entry_type === 'outward'
            ? ['dock_assigned', 'loaded']
            : ['dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted'];

        $occupied = GateEntry::query()
            ->where('entry_type', $gate->entry_type)
            ->whereIn('status', $activeStatuses)
            ->where('id', '!=', $gate->id)
            ->pluck('loading_dock')
            ->filter()
            ->all();

        return array_values(array_diff(array_values(array_unique($docks)), $occupied));
    }
}
