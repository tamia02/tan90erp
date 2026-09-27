<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\DataQualityIssue;
use App\Models\Tan90\MasterData\DataQualityRule;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Vendor;

/**
 * Backs the "POST data-quality scan" special route. Runs a small, fixed set
 * of concrete checks (rather than a general rule-authoring engine - see
 * docs/PHASE_2_SCOPE.md) and upserts tan90_data_quality_issues, keyed by
 * rule_code + record_label so re-running the scan doesn't create duplicates.
 */
class DataQualityScanner
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /** @return int number of open issues after the scan */
    public function run(): int
    {
        $this->checkUnverifiedGst(Vendor::class, 'DQ-VEN-GST', 'Vendor', 'Finance / Procurement');
        $this->checkUnverifiedGst(Location::class, 'DQ-LOC-GST', 'Location', 'Finance');
        $this->checkDuplicateVendors();
        $this->checkItemsMissingHsn();

        $this->auditLogger->logSystem('SCAN', 'Data Quality', 'Ran master data quality scan.');

        return DataQualityIssue::where('resolution_status', '!=', 'resolved')->count();
    }

    private function checkUnverifiedGst(string $modelClass, string $ruleCode, string $entityLabel, string $owner): void
    {
        $modelClass::active()->where(function ($q) {
            $q->where('gst_status', '!=', 'verified')->orWhereNull('gstin')->orWhere('gstin', '');
        })->get()->each(function ($record) use ($ruleCode, $entityLabel, $owner) {
            $this->upsertIssue($ruleCode, $entityLabel, $record->name ?? $record->code, get_class($record), $record->id,
                $record->gstin ? 'GSTIN present but not verified' : 'GSTIN missing', 'critical', $owner,
                $record->gstin ? 'Run GST verification' : 'Add GSTIN and run verification');
        });
    }

    private function checkDuplicateVendors(): void
    {
        Vendor::active()->whereNotNull('gstin')->where('gstin', '!=', '')
            ->get()
            ->groupBy('gstin')
            ->filter(fn ($group) => $group->count() > 1)
            ->each(function ($group) {
                $names = $group->pluck('name')->implode(', ');
                $first = $group->first();
                $this->upsertIssue('DQ-VEN-DUP', 'Vendor', $names, Vendor::class, $first->id,
                    'Possible duplicate vendors share the same GSTIN', 'high', 'Master Data Manager',
                    'Review duplicate candidates before approval');
            });
    }

    private function checkItemsMissingHsn(): void
    {
        Item::active()->where(function ($q) {
            $q->whereNull('hsn')->orWhere('hsn', '');
        })->get()->each(function (Item $item) {
            $this->upsertIssue('DQ-ITEM-HSN', 'Item', $item->masking_code ?? $item->id, Item::class, $item->id,
                'HSN code missing', 'medium', 'Finance', 'Assign an HSN code and GST rate');
        });
    }

    private function upsertIssue(string $ruleCode, string $entity, string $recordLabel, string $entityType, int $entityId, string $issue, string $severity, string $owner, string $suggestedAction): void
    {
        $rule = DataQualityRule::where('code', $ruleCode)->first();

        DataQualityIssue::updateOrCreate(
            ['rule_code' => $ruleCode, 'record_label' => $recordLabel],
            [
                'tan90_data_quality_rule_id' => $rule?->id,
                'entity' => $entity,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'issue' => $issue,
                'severity' => $severity,
                'owner' => $owner,
                'detected_at' => now(),
                'suggested_action' => $suggestedAction,
                'resolution_status' => 'open',
            ]
        );
    }
}
