<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\DataImportJob;
use App\Services\Tan90\MasterData\CsvImportService;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

class DataImportController extends Controller
{
    public function __construct(
        private readonly CsvImportService $importer,
        private readonly EntityRegistry $registry,
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        return view('tan90.master-data.import-export', [
            'jobs' => DataImportJob::with('startedBy')->latest()->paginate(15),
            'importableEntities' => collect($this->registry->all())->filter(fn ($e) => ! empty($e['importable'])),
        ]);
    }

    public function upload(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'create'), 403);

        $data = $request->validate([
            'entity' => 'required|string',
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $job = $this->importer->upload($request->file('file'), $data['entity'], $request->user());

        return redirect()->route('tan90.master-data.import.show', $job->id)
            ->with('status', "Preview ready: {$job->valid_rows} valid, {$job->invalid_rows} invalid, {$job->duplicate_rows} duplicate rows.");
    }

    public function show(Request $request, DataImportJob $job)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        return view('tan90.master-data.import-preview', [
            'job' => $job,
            'entity' => $this->registry->get($job->entity_type),
            'rows' => $job->rows()->orderBy('row_number')->paginate(50),
        ]);
    }

    public function commit(Request $request, DataImportJob $job)
    {
        abort_unless($this->permissions->can($request->user(), 'create'), 403);
        abort_unless($job->result === 'previewed', 422, 'Only a previewed job can be imported.');

        $job = $this->importer->commit($job, $request->user());

        return redirect()->route('tan90.master-data.import.show', $job->id)
            ->with('status', $job->result === 'queued' ? 'Large import queued for background processing.' : 'Import completed.');
    }

    public function rejectedCsv(Request $request, DataImportJob $job)
    {
        abort_unless($this->permissions->can($request->user(), 'export'), 403);

        return response($this->importer->rejectedRowsCsv($job), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="rejected-rows-' . $job->id . '.csv"',
        ]);
    }
}
