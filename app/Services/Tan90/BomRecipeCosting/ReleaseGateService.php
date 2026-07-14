<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use App\Models\Tan90\BomRecipeCosting\ReleaseGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0 workflow: Draft -> Technical Review -> QA Review -> Cost Review ->
 * Plant Trial -> Release -> MRP Ready. A gate can only be passed once every
 * earlier gate for the same object has passed, enforced with a row lock so
 * two simultaneous approvals can't both advance the same object.
 */
class ReleaseGateService
{
    private const ORDER = ['Draft', 'Technical Review', 'QA Review', 'Cost Review', 'Plant Trial', 'Release', 'MRP Ready'];

    private const STATUS_MAP = [
        'Draft' => 'draft',
        'Technical Review' => 'technical_review',
        'QA Review' => 'qa_review',
        'Cost Review' => 'cost_review',
        'Plant Trial' => 'plant_trial',
        'Release' => 'released',
        'MRP Ready' => 'mrp_ready',
    ];

    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    /** @return array{passed: bool, error: ?string} */
    public function pass(RecipeVersion|BomVersion $version, string $gate, ?string $comments = null): array
    {
        return DB::transaction(function () use ($version, $gate, $comments) {
            $version = $version instanceof RecipeVersion
                ? RecipeVersion::whereKey($version->id)->lockForUpdate()->first()
                : BomVersion::whereKey($version->id)->lockForUpdate()->first();

            $gateIndex = array_search($gate, self::ORDER, true);
            if ($gateIndex === false) {
                return ['passed' => false, 'error' => "Unknown gate [{$gate}]."];
            }

            $currentIndex = array_search($version->gate_status, array_map(fn ($g) => self::STATUS_MAP[$g], self::ORDER), true);
            if ($currentIndex !== false && $gateIndex !== $currentIndex + 1 && $gate !== 'Draft') {
                return ['passed' => false, 'error' => "Cannot pass [{$gate}] — the previous gate has not been passed yet."];
            }

            $objectType = $version instanceof RecipeVersion ? 'recipe' : 'bom';

            ReleaseGate::create([
                'code' => 'RG-' . strtoupper(Str::random(8)),
                'object_type' => $objectType,
                'object_id' => $version->id,
                'gate' => $gate,
                'status' => 'passed',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'comments' => $comments,
            ]);

            $version->update([
                'gate_status' => self::STATUS_MAP[$gate],
                'released_at' => $gate === 'Release' ? now() : $version->released_at,
                'released_by' => $gate === 'Release' ? Auth::id() : $version->released_by,
            ]);

            $this->auditTrailService->log('GATE_PASS', $version, "Passed gate [{$gate}].");

            return ['passed' => true, 'error' => null];
        });
    }

    public function history(string $objectType, int $objectId)
    {
        return ReleaseGate::where('object_type', $objectType)->where('object_id', $objectId)->orderBy('reviewed_at')->get();
    }
}
