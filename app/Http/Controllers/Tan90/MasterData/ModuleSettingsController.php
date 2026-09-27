<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Services\Tan90\MasterData\ModuleSettingsService;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

class ModuleSettingsController extends Controller
{
    public function __construct(
        private readonly ModuleSettingsService $settings,
        private readonly PermissionService $permissions,
    ) {
    }

    public function edit(Request $request, string $group = 'general')
    {
        abort_unless($this->permissions->can($request->user(), 'settings'), 403);
        abort_unless(array_key_exists($group, ModuleSettingsService::SCHEMA), 404);

        return view('tan90.master-data.settings', [
            'group' => $group,
            'groups' => array_keys(ModuleSettingsService::SCHEMA),
            'fields' => ModuleSettingsService::SCHEMA[$group],
            'values' => $this->settings->groupValues($group),
        ]);
    }

    public function update(Request $request, string $group)
    {
        abort_unless($this->permissions->can($request->user(), 'settings'), 403);
        abort_unless(array_key_exists($group, ModuleSettingsService::SCHEMA), 404);

        $this->settings->save($group, $request->except(['_token', '_method']), $request->user());

        return back()->with('status', ucfirst($group) . ' settings saved.');
    }
}
