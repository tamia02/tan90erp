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
        // Not named $component in the view data: Blade already reserves
        // that name inside <x-slot> blocks for the enclosing component
        // instance, silently shadowing a same-named view variable there.
        $brcComponent = Component::findOrFail($component);
        $this->authorize('view', $brcComponent);

        $usage = $this->whereUsed->forComponent($brcComponent);

        return view('tan90.brc.where-used.show', compact('brcComponent', 'usage'));
    }
}
