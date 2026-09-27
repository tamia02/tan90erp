<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use App\Services\Tan90\BomRecipeCosting\ReleaseGateService;
use App\Services\Tan90\BomRecipeCosting\RecipeScalingService;
use App\Services\Tan90\BomRecipeCosting\RecipeValidationService;
use App\Services\Tan90\BomRecipeCosting\RevisionService;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function __construct(
        private readonly RevisionService $revisions,
        private readonly RecipeValidationService $recipeValidation,
        private readonly RecipeScalingService $recipeScaling,
        private readonly ReleaseGateService $releaseGates,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Recipe::class);

        $recipes = Recipe::with(['finishedGood', 'currentVersion'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->string('q') . '%'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('tan90.brc.recipes.index', compact('recipes'));
    }

    public function create()
    {
        $this->authorize('create', Recipe::class);

        return view('tan90.brc.recipes.form', ['recipe' => new Recipe]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Recipe::class);

        $data = $request->validate([
            'code' => 'required|string|unique:tan90_recipes,code',
            'tan90_finished_good_id' => 'required|exists:tan90_finished_goods,id',
            'name' => 'required|string',
            'formula_tolerance_percent' => 'nullable|numeric',
        ]);
        $data['status'] = 'active';
        $data['approval_status'] = 'draft';

        $recipe = Recipe::create($data);
        $version = $this->revisions->newRecipeRevision($recipe, 'Initial recipe creation.');

        return redirect()->route('tan90.brc.recipes.show', $recipe->id)->with('status', "Recipe {$recipe->code} created with revision {$version->revision_code}.");
    }

    public function show(int $recipe)
    {
        $recipe = Recipe::with(['finishedGood', 'versions.lines.component'])->findOrFail($recipe);
        $this->authorize('view', $recipe);

        $currentVersion = $recipe->versions->firstWhere('is_current', true);
        $gateHistory = $currentVersion ? $this->releaseGates->history('recipe', $currentVersion->id) : collect();
        $validation = $currentVersion ? $this->recipeValidation->validate($currentVersion) : null;

        return view('tan90.brc.recipes.show', compact('recipe', 'currentVersion', 'gateHistory', 'validation'));
    }

    public function newRevision(Request $request, int $recipe)
    {
        $recipe = Recipe::findOrFail($recipe);
        $this->authorize('update', $recipe);

        $request->validate(['reason' => 'required|string']);
        $version = $this->revisions->newRecipeRevision($recipe, $request->string('reason')->value(), $request->input('description'));

        return redirect()->route('tan90.brc.recipes.show', $recipe->id)->with('status', "New revision {$version->revision_code} created.");
    }

    public function storeLine(Request $request, int $version)
    {
        $version = RecipeVersion::findOrFail($version);
        $this->authorize('update', $version->recipe);
        abort_if($this->revisions->isImmutable($version), 422, 'This revision is released and immutable — create a new revision to change lines.');

        $data = $request->validate([
            'tan90_component_id' => 'required|exists:tan90_components,id',
            'sequence' => 'nullable|integer',
            'percentage' => 'required|numeric',
            'quantity' => 'nullable|numeric',
            'uom' => 'nullable|string',
            'wastage_percent' => 'nullable|numeric',
            'is_alternate' => 'nullable|boolean',
        ]);
        $data['sequence'] ??= $version->lines()->max('sequence') + 1;

        $version->lines()->create($data);

        return back()->with('status', 'Recipe line added.');
    }

    public function destroyLine(int $version, int $line)
    {
        $version = RecipeVersion::findOrFail($version);
        $this->authorize('update', $version->recipe);
        abort_if($this->revisions->isImmutable($version), 422, 'This revision is released and immutable.');

        $version->lines()->whereKey($line)->delete();

        return back()->with('status', 'Recipe line removed.');
    }

    public function validateFormula(int $version)
    {
        $version = RecipeVersion::findOrFail($version);
        $this->authorize('view', $version->recipe);

        return response()->json($this->recipeValidation->validate($version));
    }

    public function scale(Request $request, int $version)
    {
        $version = RecipeVersion::findOrFail($version);
        $this->authorize('view', $version->recipe);

        $data = $request->validate(['batch_size' => 'required|numeric|min:0.0001']);

        return response()->json($this->recipeScaling->scale($version, (float) $data['batch_size']));
    }

    public function passGate(Request $request, int $version)
    {
        $version = RecipeVersion::findOrFail($version);
        $recipe = $version->recipe;
        $this->authorize('approve', $recipe);

        $data = $request->validate(['gate' => 'required|string', 'comments' => 'nullable|string']);

        $validation = $this->recipeValidation->validate($version);
        if ($data['gate'] === 'QA Review' && ! $validation['valid']) {
            return back()->withErrors(['gate' => implode(' ', $validation['errors'])]);
        }

        $result = $this->releaseGates->pass($version, $data['gate'], $data['comments'] ?? null);
        if (! $result['passed']) {
            return back()->withErrors(['gate' => $result['error']]);
        }

        return back()->with('status', "Gate '{$data['gate']}' passed.");
    }
}
