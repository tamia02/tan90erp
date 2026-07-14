<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\EngineeringChangeOrder;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use Illuminate\Support\Facades\Auth;

/**
 * "Command Center" + "My Tasks" screens: a role-agnostic summary of what
 * needs attention, plus items awaiting the current user's own action.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $counts = [
            'recipes' => Recipe::count(),
            'boms' => Bom::count(),
            'pending_technical_review' => RecipeVersion::where('gate_status', 'technical_review')->count(),
            'pending_qa_review' => RecipeVersion::where('gate_status', 'qa_review')->count(),
            'pending_cost_review' => RecipeVersion::where('gate_status', 'cost_review')->count(),
            'pending_plant_trial' => RecipeVersion::where('gate_status', 'plant_trial')->count(),
            'released' => RecipeVersion::where('gate_status', 'released')->count(),
            'mrp_ready' => RecipeVersion::where('gate_status', 'mrp_ready')->count(),
            'open_ecos' => EngineeringChangeOrder::where('status', '!=', 'implemented')->count(),
        ];

        $myTasks = EngineeringChangeOrder::where('status', 'draft')
            ->where('requested_by', Auth::id())
            ->latest('requested_at')
            ->limit(10)
            ->get();

        $recentRevisions = RecipeVersion::with('recipe.finishedGood')->latest('updated_at')->limit(10)->get();

        return view('tan90.brc.dashboard', compact('counts', 'myTasks', 'recentRevisions'));
    }
}
