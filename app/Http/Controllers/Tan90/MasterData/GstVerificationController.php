<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\GstVerificationService;
use Illuminate\Http\Request;

class GstVerificationController extends Controller
{
    public function __construct(
        private readonly GstVerificationService $gst,
        private readonly EntityRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * POST /tan90/master-data/{entity}/{id}/verify-gst
     * Works for any entity carrying a gstin/gst_status pair (locations,
     * location-gst-registrations, vendors).
     */
    public function verify(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('update', $record);

        abort_unless($record->getAttribute('gstin') !== null, 422, "{$config['singular']} has no GSTIN to verify.");

        $result = $this->gst->verify($record->gstin);
        $record->gst_status = $result['status'];
        $record->save();

        $this->auditLogger->log('GST_VERIFY', $record, "GST verification result: {$result['status']}. {$result['message']}");

        return back()->with('status', $result['message']);
    }
}
