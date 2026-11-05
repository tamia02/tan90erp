<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\MasterAuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->route('tan90.master-data.audit-logs', $request->query());
    }

    public function export(): StreamedResponse
    {
        $logs = MasterAuditLog::query()->with('user')->latest('occurred_at')->get();

        return response()->streamDownload(function () use ($logs): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['Occurred at', 'Event', 'Module', 'Record', 'User', 'Summary', 'Changed fields']);
            foreach ($logs as $log) {
                fputcsv($stream, [
                    $log->occurred_at?->toIso8601String(), $log->event, $log->module,
                    $log->record_label, $log->user?->name, $log->summary,
                    json_encode($log->changed_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
            fclose($stream);
        }, 'tan90-audit-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
