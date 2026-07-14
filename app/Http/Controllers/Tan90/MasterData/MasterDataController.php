<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tan90\MasterData\StoreMasterDataRequest;
use App\Http\Requests\Tan90\MasterData\UpdateMasterDataRequest;
use App\Models\Tan90\MasterData\ApprovalProgress;
use App\Models\Tan90\MasterData\ApprovalWorkflowStep;
use App\Models\Tan90\MasterData\MasterAttachment;
use App\Models\Tan90\MasterData\MasterAuditLog;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Models\Tan90\MasterData\NumberSeries;
use App\Services\Tan90\MasterData\ApprovalService;
use App\Services\Tan90\MasterData\DocumentRuleEnforcer;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One controller drives every entity in config/tan90_master_data.php via the
 * {entity} route segment, mirroring the demo's single generic
 * renderGenericList()/renderDetail()/saveRecord() functions in app.js.
 *
 * Critical-field edits on an already-approved record are redirected into a
 * MasterChangeRequest by update() rather than applied directly - see
 * ApprovalService::requiresChangeRequest().
 */
class MasterDataController extends Controller
{
    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly PermissionService $permissions,
        private readonly ApprovalService $approvals,
        private readonly DocumentRuleEnforcer $documentRules,
    ) {
    }

    public function index(Request $request, string $entity)
    {
        $config = $this->registry->get($entity);
        $this->authorize('viewAny', $config['model']);

        $query = $this->baseQuery($request, $config);
        $records = $query->paginate(max(10, min(50, (int) $request->integer('per_page', 10))))->withQueryString();

        return view('tan90.master-data.index', [
            'entity' => $entity,
            'config' => $config,
            'records' => $records,
        ]);
    }

    public function create(string $entity)
    {
        $config = $this->registry->get($entity);
        $this->authorize('create', $config['model']);

        if (empty($config['fields'])) {
            abort(404);
        }

        return view('tan90.master-data.form', [
            'entity' => $entity,
            'config' => $config,
            'record' => new $config['model'],
            'mode' => 'create',
            'hasNumberSeries' => NumberSeries::where('module', $config['title'])->where('status', 'active')->exists(),
        ]);
    }

    public function store(StoreMasterDataRequest $request, string $entity)
    {
        $config = $this->registry->get($entity);
        $data = $request->validated();
        $data['status'] = 'active';
        $data['approval_status'] = empty($config['no_approval']) ? 'draft' : 'active';

        $record = $config['model']::create($data);

        return redirect()
            ->route('tan90.master-data.show', [$entity, $record->id])
            ->with('status', "{$config['singular']} created.");
    }

    public function show(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $this->findWithRelations($config, $id);
        $this->authorize('view', $record);

        $auditTrail = MasterAuditLog::where('entity_type', $config['model'])
            ->where('entity_id', $record->id)
            ->latest('occurred_at')
            ->limit(15)
            ->get();

        $changeRequests = MasterChangeRequest::where('entity_type', $entity)
            ->where('entity_id', $record->id)
            ->latest()
            ->limit(10)
            ->get();

        $workflowProgress = ApprovalProgress::where('entity_type', $entity)
            ->where('entity_id', $record->id)
            ->with('workflow')
            ->latest('id')
            ->first();
        $workflowSteps = $workflowProgress?->tan90_approval_workflow_id
            ? ApprovalWorkflowStep::where('tan90_approval_workflow_id', $workflowProgress->tan90_approval_workflow_id)->orderBy('step_order')->get()
            : collect();

        $documentRule = $this->documentRules->ruleFor($config);
        $attachments = MasterAttachment::where('entity_type', $entity)->where('entity_id', $record->id)->with('uploader')->get();
        $missingDocuments = $this->documentRules->missingDocuments($config, $record);

        return view('tan90.master-data.show', [
            'entity' => $entity,
            'config' => $config,
            'record' => $record,
            'auditTrail' => $auditTrail,
            'changeRequests' => $changeRequests,
            'workflowProgress' => $workflowProgress,
            'workflowSteps' => $workflowSteps,
            'documentRule' => $documentRule,
            'attachments' => $attachments,
            'missingDocuments' => $missingDocuments,
        ]);
    }

    public function edit(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('update', $record);

        return view('tan90.master-data.form', [
            'entity' => $entity,
            'config' => $config,
            'record' => $record,
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateMasterDataRequest $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $data = $request->validated();

        $dirtyFields = array_keys(array_filter(
            $data,
            fn ($value, $key) => (string) $record->getAttribute($key) !== (string) $value,
            ARRAY_FILTER_USE_BOTH
        ));

        if ($this->approvals->requiresChangeRequest($config, $record, $dirtyFields)) {
            $critical = array_intersect($dirtyFields, $config['critical_fields']);
            $this->approvals->requestCriticalChange(
                $config,
                $record,
                array_intersect_key($data, array_flip($critical)),
                $request->user(),
                $request->input('change_reason'),
                $request->input('priority', 'Medium')
            );

            return redirect()
                ->route('tan90.master-data.show', [$entity, $record->id])
                ->with('status', "Critical field change submitted as a change request for approval.");
        }

        $record->update($data);

        return redirect()
            ->route('tan90.master-data.show', [$entity, $record->id])
            ->with('status', "{$config['singular']} updated.");
    }

    public function destroy(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('delete', $record);

        if (! empty($config['no_soft_delete'])) {
            // Reference/config tables (number series, SLA policies, ...) have no
            // deleted_at column - delete() here is a hard delete, not an archive.
            $record->delete();

            return redirect()
                ->route('tan90.master-data.index', $entity)
                ->with('status', "{$config['singular']} deleted.");
        }

        // delete() alone writes the single ARCHIVE audit entry (see IsMasterRecord::deleted());
        // the status flip is saved quietly so it doesn't also fire a redundant UPDATE entry.
        $record->delete();
        $record->status = 'archived';
        $record->saveQuietly();

        return redirect()
            ->route('tan90.master-data.index', $entity)
            ->with('status', "{$config['singular']} archived.");
    }

    public function restore(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        abort_if(! empty($config['no_soft_delete']), 404, "{$config['singular']} does not support restore.");

        $record = $config['model']::withTrashed()->findOrFail($id);
        $this->authorize('restore', $record);

        // restore() alone writes the single RESTORE audit entry; the status flip is
        // saved quietly to avoid a second, redundant UPDATE entry (see above).
        $record->restore();
        $record->status = 'active';
        $record->saveQuietly();

        return back()->with('status', "{$config['singular']} restored.");
    }

    public function submit(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('submit', $record);

        $missingDocs = $this->documentRules->missingDocuments($config, $record);
        if ($missingDocs) {
            return back()->withErrors([
                'documents' => 'Upload the required document(s) before submitting: ' . implode(', ', $missingDocs) . '.',
            ]);
        }

        $this->approvals->submit($config, $record);

        return back()->with('status', "{$config['singular']} submitted for approval.");
    }

    public function approve(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('approve', $record);

        try {
            $this->approvals->approve($config, $record, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', $record->fresh()->approval_status === 'approved'
            ? "{$config['singular']} approved."
            : "{$config['singular']} approved for this workflow step; awaiting the next step.");
    }

    public function reject(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('reject', $record);

        $this->approvals->reject($config, $record, $request->user(), $request->input('notes'));

        return back()->with('status', "{$config['singular']} rejected.");
    }

    public function export(Request $request, string $entity): StreamedResponse
    {
        $config = $this->registry->get($entity);
        $this->authorize('export', $config['model']);

        $query = $this->baseQuery($request, $config);
        $columns = $config['columns'];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            $query->chunk(200, function ($chunk) use ($out, $columns) {
                foreach ($chunk as $record) {
                    fputcsv($out, array_map(fn ($c) => $this->registry->columnValue($record, $c), $columns));
                }
            });
            fclose($out);
        }, Str::slug($config['title']) . '-' . now()->format('Ymd-His') . '.csv');
    }

    private function baseQuery(Request $request, array $config): Builder
    {
        $query = $config['model']::query();

        $query = $this->permissions->scopeQuery($query, $request->user(), $config);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        } else {
            $query->where('status', '!=', 'archived');
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->string('approval_status'));
        }

        if ($request->filled('q')) {
            $term = '%' . $request->string('q') . '%';
            $query->where(function (Builder $inner) use ($term, $config) {
                foreach ($config['searchable'] as $field) {
                    $inner->orWhere($field, 'like', $term);
                }
            });
        }

        $sort = $request->string('sort')->value();
        if ($sort && in_array($sort, array_column($config['fields'], 'name'), true)) {
            $query->orderBy($sort, $request->string('dir', 'asc')->value() === 'desc' ? 'desc' : 'asc');
        } else {
            $query->latest('updated_at');
        }

        foreach ($config['columns'] as $column) {
            if (str_contains($column, '.')) {
                $query->with(explode('.', $column)[0]);
            }
        }

        return $query;
    }

    private function findWithRelations(array $config, int $id): Model
    {
        $relations = collect($config['columns'])
            ->filter(fn ($c) => str_contains($c, '.'))
            ->map(fn ($c) => explode('.', $c)[0])
            ->unique()
            ->all();

        return $config['model']::with($relations)->findOrFail($id);
    }
}
