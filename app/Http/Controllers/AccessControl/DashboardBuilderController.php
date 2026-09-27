<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\DashboardTemplate;
use App\Models\Access\DashboardWidgetCatalog;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardBuilderController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->canBuild($request), 403);

        return view('access-control.dashboard-builder', [
            'templates' => DashboardTemplate::withCount('items')->latest()->paginate(10),
            'widgets' => DashboardWidgetCatalog::where('status', 'active')->orderBy('module')->orderBy('title')->get(),
            'roles' => AccessRole::where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'teams' => AccessTeam::where('status', 'active')->orderBy('name')->get(),
            'users' => User::where('access_mode', 'advanced')->orderBy('name')->get(),
            'selectedTemplate' => $request->filled('template') ? DashboardTemplate::with('items')->find($request->integer('template')) : null,
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($this->canBuild($request), 403);

        $data = $request->validate([
            'template_id' => ['nullable', 'exists:dashboard_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'owner_type' => ['required', Rule::in(['role', 'team', 'user'])],
            'owner_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'items' => ['array'],
            'items.*.widget_key' => ['required', 'exists:dashboard_widget_catalog,key'],
            'items.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.visual' => ['nullable', 'string', 'max:40'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.visible' => ['boolean'],
            'items.*.mandatory' => ['boolean'],
            'items.*.position_locked' => ['boolean'],
            'items.*.size_locked' => ['boolean'],
            'items.*.config_locked' => ['boolean'],
        ]);

        $this->validateOwner($data['owner_type'], (int) $data['owner_id']);

        $attributes = [
            'name' => $data['name'],
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'status' => $data['status'],
            'responsive_layouts_json' => ['desktop' => 12, 'tablet' => 8, 'mobile' => 4],
            'created_by' => $request->user()->id,
            'published_by' => $data['status'] === 'published' ? $request->user()->id : null,
            'published_at' => $data['status'] === 'published' ? now() : null,
        ];

        if ($data['template_id'] ?? null) {
            $template = DashboardTemplate::findOrFail($data['template_id']);
            $template->fill($attributes + ['version' => $template->version + 1])->save();
        } else {
            $template = DashboardTemplate::create($attributes + ['uuid' => (string) Str::uuid(), 'version' => 1]);
        }

        $template->items()->delete();
        foreach ($data['items'] ?? [] as $index => $item) {
            $template->items()->create([
                'widget_key' => $item['widget_key'],
                'page_key' => 'workspace',
                'tab_key' => 'default',
                'x' => 0,
                'y' => $index,
                'w' => $item['w'],
                'h' => $item['h'],
                'mobile_x' => 0,
                'mobile_y' => $index,
                'mobile_w' => min(4, $item['w']),
                'mobile_h' => $item['h'],
                'visible' => (bool) ($item['visible'] ?? false),
                'mandatory' => (bool) ($item['mandatory'] ?? false),
                'position_locked' => (bool) ($item['position_locked'] ?? false),
                'size_locked' => (bool) ($item['size_locked'] ?? false),
                'config_locked' => (bool) ($item['config_locked'] ?? false),
                'config_json' => ['visual' => $item['visual'] ?? 'stat', 'title' => $item['title'] ?? null],
                'sort_order' => $index + 1,
            ]);
        }

        $this->access->audit($request->user(), 'dashboard.template.saved', $template, null, $template->load('items')->toArray());

        return redirect()->route('access.dashboard-builder.index', ['template' => $template->id])->with('status', 'Dashboard template saved.');
    }

    private function canBuild(Request $request): bool
    {
        $user = $request->user();

        return $this->access->can($user, 'dashboard.builder.role')
            || $this->access->can($user, 'dashboard.builder.team')
            || $this->access->can($user, 'dashboard.builder.user')
            || $this->access->can($user, 'access.roles.manage');
    }

    private function validateOwner(string $type, int $id): void
    {
        $exists = match ($type) {
            'role' => AccessRole::whereKey($id)->exists(),
            'team' => AccessTeam::whereKey($id)->exists(),
            'user' => User::whereKey($id)->exists(),
        };

        abort_unless($exists, 422, 'Dashboard owner is invalid.');
    }
}
