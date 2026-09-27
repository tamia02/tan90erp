<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\DataQualityIssue;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\DataQualityScanner;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

class DataQualityController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        $issues = DataQualityIssue::query()->latest('detected_at')->paginate(25);

        return view('tan90.master-data.data-quality', [
            'issues' => $issues,
            'critical' => DataQualityIssue::where('severity', 'critical')->where('resolution_status', '!=', 'resolved')->count(),
            'open' => DataQualityIssue::where('resolution_status', '!=', 'resolved')->count(),
        ]);
    }

    public function scan(Request $request, DataQualityScanner $scanner)
    {
        abort_unless($this->permissions->can($request->user(), 'create'), 403);

        $openCount = $scanner->run();

        return back()->with('status', "Scan complete. {$openCount} open issue(s).");
    }

    public function resolve(Request $request, DataQualityIssue $issue)
    {
        abort_unless($this->permissions->can($request->user(), 'edit'), 403);

        $issue->update(['resolution_status' => 'resolved']);
        $this->auditLogger->logSystem('RESOLVE', 'Data Quality', "Resolved {$issue->rule_code}: {$issue->record_label}.");

        return back()->with('status', 'Issue marked resolved.');
    }
}
