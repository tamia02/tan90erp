<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Plant;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocationWorkspaceController extends Controller
{
    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filter = $request->string('type')->toString();
        $search = trim($request->string('search')->toString());
        $definitions = [
            'site' => [Location::class, 'locations', 'Business site', ['code', 'name', 'city', 'state']],
            'plant' => [Plant::class, 'plants', 'Plant', ['code', 'name', 'manager']],
            'warehouse' => [Warehouse::class, 'warehouses', 'Warehouse', ['code', 'name', 'manager']],
        ];
        $rows = collect();

        foreach ($definitions as $kind => [$model, $slug, $label, $searchFields]) {
            if ($filter !== '' && $filter !== $kind) {
                continue;
            }
            $this->authorize('viewAny', $model);
            $config = $this->registry->get($slug);
            $query = $this->permissions->scopeQuery($model::query(), $user, $config);
            if ($model === Plant::class) {
                $query->with(['location', 'businessUnit']);
            } elseif ($model === Warehouse::class) {
                $query->with(['location', 'plant']);
            }
            if ($search !== '') {
                $query->where(fn ($q) => collect($searchFields)->each(fn ($field) => $q->orWhere($field, 'like', '%'.$search.'%')));
            }
            foreach ($query->orderBy('code')->get() as $record) {
                if (! Gate::allows('view', $record)) {
                    continue;
                }
                $rows->push(['kind' => $kind, 'label' => $label, 'entity' => $slug, 'record' => $record]);
            }
        }

        return view('tan90.master-data.location-workspace', [
            'rows' => $rows->sortBy(fn ($row) => $row['record']->code)->values(),
            'filter' => $filter,
            'search' => $search,
            'canCreate' => $user->isAdmin(),
        ]);
    }

    public function create(Request $request)
    {
        $kind = $request->string('type')->toString();
        $entity = match ($kind) {
            'site' => 'locations',
            'plant' => 'plants',
            'warehouse' => 'warehouses',
            default => abort(422, 'Choose a valid location type.'),
        };

        return redirect()->route('tan90.master-data.create', $entity);
    }
}
