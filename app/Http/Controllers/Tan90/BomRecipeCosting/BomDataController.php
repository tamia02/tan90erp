<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tan90\BomRecipeCosting\StoreBomDataRequest;
use App\Http\Requests\Tan90\BomRecipeCosting\UpdateBomDataRequest;
use App\Models\Tan90\BomRecipeCosting\AuditLog;
use App\Services\Tan90\BomRecipeCosting\BomApprovalService;
use App\Services\Tan90\BomRecipeCosting\EntityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One controller drives every simple reference entity in
 * config/tan90_bom_recipe_costing.php, mirroring Master Data's
 * MasterDataController. Recipes/BOMs/Routings/Cost Sheets/ECOs have their
 * own dedicated controllers instead — see routes/tan90_bom_recipe_costing.php.
 */
class BomDataController extends Controller
{
    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly BomApprovalService $approvals,
    ) {
    }

    public function index(Request $request, string $entity)
    {
        $config = $this->registry->get($entity);
        $this->authorize('viewAny', $config['model']);

        $records = $this->baseQuery($request, $config)
            ->paginate(max(10, min(50, (int) $request->integer('per_page', 10))))
            ->withQueryString();

        return view('tan90.brc.index', ['entity' => $entity, 'config' => $config, 'records' => $records]);
    }

    public function create(string $entity)
    {
        $config = $this->registry->get($entity);
        $this->authorize('create', $config['model']);

        return view('tan90.brc.form', [
            'entity' => $entity, 'config' => $config, 'record' => new $config['model'], 'mode' => 'create',
        ]);
    }

    public function store(StoreBomDataRequest $request, string $entity)
    {
        $config = $this->registry->get($entity);
        $data = $request->validated();
        $data['status'] = 'active';
        $data['approval_status'] = 'draft';

        $record = $config['model']::create($data);

        return redirect()->route('tan90.brc.show', [$entity, $record->id])->with('status', "{$config['singular']} created.");
    }

    public function show(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $this->findWithRelations($config, $id);
        $this->authorize('view', $record);

        $auditTrail = AuditLog::where('auditable_type', $config['model'])
            ->where('auditable_id', $record->id)
            ->latest('created_at')
            ->limit(15)
            ->get();

        return view('tan90.brc.show', ['entity' => $entity, 'config' => $config, 'record' => $record, 'auditTrail' => $auditTrail]);
    }

    public function edit(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('update', $record);

        return view('tan90.brc.form', ['entity' => $entity, 'config' => $config, 'record' => $record, 'mode' => 'edit']);
    }

    public function update(UpdateBomDataRequest $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $record->update($request->validated());

        return redirect()->route('tan90.brc.show', [$entity, $record->id])->with('status', "{$config['singular']} updated.");
    }

    public function destroy(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('delete', $record);

        $record->delete();
        $record->status = 'archived';
        $record->saveQuietly();

        return redirect()->route('tan90.brc.index', $entity)->with('status', "{$config['singular']} archived.");
    }

    public function restore(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::withTrashed()->findOrFail($id);
        $this->authorize('restore', $record);

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

        $this->approvals->submit($record);

        return back()->with('status', "{$config['singular']} submitted for approval.");
    }

    public function approve(string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('approve', $record);

        $this->approvals->approve($record);

        return back()->with('status', "{$config['singular']} approved.");
    }

    public function reject(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('reject', $record);

        $this->approvals->reject($record, $request->input('notes'));

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

        foreach ($config['columns'] as $column) {
            if (str_contains($column, '.')) {
                $query->with(explode('.', $column)[0]);
            }
        }

        return $query->latest('updated_at');
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
