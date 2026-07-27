<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessSavedView;
use App\Models\Access\AccessTeam;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'views.use_assigned') || $this->access->can($request->user(), 'views.manage_user'), 403);

        return view('access-control.views-index', [
            'views' => AccessSavedView::orderBy('module')->orderBy('name')->paginate(12),
            'roles' => AccessRole::orderBy('name')->get(),
            'teams' => AccessTeam::orderBy('name')->get(),
        ]);
    }
}
