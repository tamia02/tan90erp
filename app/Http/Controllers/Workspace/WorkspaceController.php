<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Access\UserDashboardLayout;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'workspace.view'), 403);

        return view('workspace.index', ['widgets' => $this->widgetCards($request)]);
    }

    public function customise(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'workspace.customise'), 403);

        return view('workspace.customise', ['widgets' => $this->widgetCards($request), 'layouts' => UserDashboardLayout::where('user_id', $request->user()->id)->get()->keyBy('widget_key')]);
    }

    public function save(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'workspace.customise'), 403);
        $data = $request->validate([
            'layouts' => ['required', 'array'],
            'layouts.*.widget_key' => ['required', 'string', 'exists:dashboard_widgets,key'],
            'layouts.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layouts.*.y' => ['required', 'integer', 'min:0', 'max:100'],
            'layouts.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layouts.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'layouts.*.visible' => ['boolean'],
        ]);
        $allowed = $this->access->widgetsFor($request->user())->pluck('key');
        foreach ($data['layouts'] as $layout) {
            abort_unless($allowed->contains($layout['widget_key']), 403);
            UserDashboardLayout::updateOrCreate(
                ['user_id' => $request->user()->id, 'widget_key' => $layout['widget_key']],
                $layout + ['config_json' => null]
            );
        }
        $this->access->audit($request->user(), 'workspace.layout.saved', $request->user(), null, $data);

        return back()->with('status', 'Workspace layout saved.');
    }

    private function widgetCards(Request $request)
    {
        return $this->access->widgetsFor($request->user())->map(function ($widget) use ($request) {
            $provider = app($widget->provider_class);

            return ['widget' => $widget, 'data' => $provider->data($request->user())];
        });
    }
}
