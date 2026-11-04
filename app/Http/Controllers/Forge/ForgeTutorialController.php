<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class ForgeTutorialController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $isAdvancedSuperAdmin = $user->super_admin && $user->access_mode !== 'legacy';

        abort_unless($isAdvancedSuperAdmin || $this->access->can($user, 'forge.dashboard.view'), 403);

        return view('forge.tutorial');
    }
}
