<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Support\SiteLocation;

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

        $lastDock = GateEntry::withoutSiteScope()
            ->where('entry_type', $gate->entry_type)
            ->whereIn('loading_dock', $docks)
            ->whereNotNull('dock_assigned_at')
            ->latest('dock_assigned_at')
            ->limit(100)
            ->get(['id', 'location', 'location_id', 'loading_dock', 'dock_assigned_at'])
            ->first(fn (GateEntry $entry) => $this->sameLocation($gate, $entry))?->loading_dock;

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
        return array_values(array_diff(array_values(array_unique($docks)), array_keys($this->occupied($gate, $docks))));
    }

    /** @return array<string, GateEntry> Occupied bay name => active truck entry. */
    public function occupied(GateEntry $gate, array $docks): array
    {
        $docks = array_values(array_unique(array_filter(array_map('strval', $docks))));
        if ($docks === []) {
            return [];
        }

        $activeStatuses = $gate->entry_type === 'outward'
            ? ['dock_assigned', 'loaded']
            : ['dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted'];

        return GateEntry::withoutSiteScope()
            ->where('entry_type', $gate->entry_type)
            ->whereIn('status', $activeStatuses)
            ->where('id', '!=', $gate->id)
            ->whereNull('exited_at')
            ->whereIn('loading_dock', $docks)
            ->get(['id', 'gate_no', 'location', 'location_id', 'vehicle_number', 'loading_dock', 'status'])
            ->filter(fn (GateEntry $entry) => $this->sameLocation($gate, $entry))
            ->keyBy('loading_dock')
            ->all();
    }

    private function sameLocation(GateEntry $reference, GateEntry $candidate): bool
    {
        $referenceId = $reference->location_id ?: SiteLocation::idForName($reference->location);
        $candidateId = $candidate->location_id ?: SiteLocation::idForName($candidate->location);

        if ($referenceId && $candidateId) {
            return (int) $referenceId === (int) $candidateId;
        }
        if ($referenceId) {
            return SiteLocation::matchesName($candidate->location, (int) $referenceId);
        }
        if ($candidateId) {
            return SiteLocation::matchesName($reference->location, (int) $candidateId);
        }

        return mb_strtolower(trim((string) $reference->location)) === mb_strtolower(trim((string) $candidate->location));
    }
}
