<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Component;
use App\Services\Tan90\BomRecipeCosting\WhereUsedService;

class WhereUsedController extends Controller
{
    public function __construct(private readonly WhereUsedService $whereUsed)
    {
    }

    public function show(int $component)
    {
        $component = Component::findOrFail($component);
        $this->authorize('view', $component);

        $usage = $this->whereUsed->forComponent($component);

        return view('tan90.brc.where-used.show', compact('component', 'usage'));
    }
}
