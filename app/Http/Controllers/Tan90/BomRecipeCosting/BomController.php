<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Services\Tan90\BomRecipeCosting\BomValidationService;
use App\Services\Tan90\BomRecipeCosting\ReleaseGateService;
use App\Services\Tan90\BomRecipeCosting\RevisionService;
use App\Services\Tan90\BomRecipeCosting\WhereUsedService;
use Illuminate\Http\Request;

class BomController extends Controller
{
    public function __construct(
        private readonly RevisionService $revisions,
        private readonly BomValidationService $bomValidation,
        private readonly ReleaseGateService $releaseGates,
        private readonly WhereUsedService $whereUsed,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Bom::class);

        $boms = Bom::with(['finishedGood', 'currentVersion'])
            ->when($request->filled('bom_type'), fn ($q) => $q->where('bom_type', $request->string('bom_type')))
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%' . $request->string('q') . '%'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('tan90.brc.boms.index', compact('boms'));
    }

    public function create()
    {
        $this->authorize('create', Bom::class);

        return view('tan90.brc.boms.form', ['bom' => new Bom]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Bom::class);

        $data = $request->validate([
            'code' => 'required|string|unique:tan90_boms,code',
            'tan90_finished_good_id' => 'required|exists:tan90_finished_goods,id',
            'bom_type' => 'required|in:production,packaging,service',
        ]);
        $data['status'] = 'active';
        $data['approval_status'] = 'draft';

        $bom = Bom::create($data);
        $version = $this->revisions->newBomRevision($bom, 'Initial BOM creation.');

        return redirect()->route('tan90.brc.boms.show', $bom->id)->with('status', "BOM {$bom->code} created with revision {$version->revision_code}.");
    }

    public function show(int $bom)
    {
        $bom = Bom::with(['finishedGood', 'versions.lines.component', 'versions.lines.subBom'])->findOrFail($bom);
        $this->authorize('view', $bom);

        $currentVersion = $bom->versions->firstWhere('is_current', true);
        $gateHistory = $currentVersion ? $this->releaseGates->history('bom', $currentVersion->id) : collect();
        $validation = $currentVersion ? $this->bomValidation->validate($currentVersion) : null;
        $usedIn = $this->whereUsed->forSubBom($bom);

        return view('tan90.brc.boms.show', compact('bom', 'currentVersion', 'gateHistory', 'validation', 'usedIn'));
    }

    public function newRevision(Request $request, int $bom)
    {
        $bom = Bom::findOrFail($bom);
        $this->authorize('update', $bom);

        $request->validate(['reason' => 'required|string']);
        $version = $this->revisions->newBomRevision($bom, $request->string('reason')->value(), $request->input('description'));

        return redirect()->route('tan90.brc.boms.show', $bom->id)->with('status', "New revision {$version->revision_code} created.");
    }

    public function storeLine(Request $request, int $version)
    {
        $version = BomVersion::findOrFail($version);
        $this->authorize('update', $version->bom);
        abort_if($this->revisions->isImmutable($version), 422, 'This revision is released and immutable — create a new revision to change lines.');

        $data = $request->validate([
            'line_type' => 'required|in:component,sub_bom',
            'tan90_component_id' => 'nullable|required_if:line_type,component|exists:tan90_components,id',
            'tan90_sub_bom_id' => 'nullable|required_if:line_type,sub_bom|exists:tan90_boms,id',
            'sequence' => 'nullable|integer',
            'quantity' => 'required|numeric|min:0.0001',
            'uom' => 'nullable|string',
            'wastage_percent' => 'nullable|numeric',
            'is_alternate' => 'nullable|boolean',
        ]);
        $data['sequence'] ??= $version->lines()->max('sequence') + 1;

        $version->lines()->create($data);

        $validation = $this->bomValidation->validate($version->fresh(['lines']));
        if (! $validation['valid']) {
            $version->lines()->latest('id')->first()->delete();

            return back()->withErrors(['line' => implode(' ', $validation['errors'])]);
        }

        return back()->with('status', 'BOM line added.');
    }

    public function destroyLine(int $version, int $line)
    {
        $version = BomVersion::findOrFail($version);
        $this->authorize('update', $version->bom);
        abort_if($this->revisions->isImmutable($version), 422, 'This revision is released and immutable.');

        $version->lines()->whereKey($line)->delete();

        return back()->with('status', 'BOM line removed.');
    }

    public function passGate(Request $request, int $version)
    {
        $version = BomVersion::findOrFail($version);
        $bom = $version->bom;
        $this->authorize('approve', $bom);

        $data = $request->validate(['gate' => 'required|string', 'comments' => 'nullable|string']);

        $validation = $this->bomValidation->validate($version);
        if (! $validation['valid']) {
            return back()->withErrors(['gate' => implode(' ', $validation['errors'])]);
        }

        $result = $this->releaseGates->pass($version, $data['gate'], $data['comments'] ?? null);
        if (! $result['passed']) {
            return back()->withErrors(['gate' => $result['error']]);
        }

        return back()->with('status', "Gate '{$data['gate']}' passed.");
    }

    public function storeYield(Request $request, int $version)
    {
        $version = BomVersion::findOrFail($version);
        $this->authorize('update', $version->bom);

        $data = $request->validate([
            'batch_size' => 'required|numeric',
            'expected_yield' => 'nullable|numeric',
            'actual_yield' => 'nullable|numeric',
            'yield_percent' => 'nullable|numeric',
            'loss_percent' => 'nullable|numeric',
        ]);
        $data['recorded_at'] = now();

        $version->yieldRecords()->create($data);

        return back()->with('status', 'Yield record added.');
    }
}
