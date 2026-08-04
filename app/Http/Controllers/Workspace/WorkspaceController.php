<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessRoleDashboardLayout;
use App\Models\Access\UserDashboardLayout;
use App\Models\Workspace\WorkspaceApproval;
use App\Models\Workspace\WorkspaceException;
use App\Models\Workspace\WorkspaceTask;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.view'), 403);

        $personal = UserDashboardLayout::where('user_id', $user->id)->get()->keyBy('widget_key');
        $cards = $this->widgetCards($request, $this->roleLocks($user), $personal);

        // Mandatory widgets always show regardless of a personal hide; the
        // rest follow the user's own visibility choice, then sort order.
        $cards = $cards
            ->filter(fn ($card) => $card['locks']['mandatory'] || ($card['layout']['visible'] ?? true))
            ->sortBy(fn ($card) => $card['layout']['y'] ?? 999)
            ->values();

        return view('workspace.index', ['widgets' => $cards, 'queues' => $this->queueSummary($user)]);
    }

    /**
     * @return array<string, array{count: int, route: string}|null>
     */
    private function queueSummary(\App\Models\User $user): array
    {
        $scope = $this->access->teamScopedUserIds($user);

        return [
            'tasks' => $this->access->can($user, 'workspace.tasks.view') ? [
                'count' => WorkspaceTask::where('status', '!=', 'completed')
                    ->when($scope, fn ($q) => $q->whereIn('assigned_to', $scope)->orWhereIn('created_by', $scope))
                    ->count(),
                'route' => 'workspace.tasks.index',
            ] : null,
            'approvals' => $this->access->can($user, 'workspace.approvals.view') ? [
                'count' => WorkspaceApproval::where('status', 'pending')
                    ->when($scope, fn ($q) => $q->whereIn('requested_by', $scope)->orWhereIn('approver_id', $scope))
                    ->count(),
                'route' => 'workspace.approvals.index',
            ] : null,
            'exceptions' => $this->access->can($user, 'workspace.exceptions.view') ? [
                'count' => WorkspaceException::where('status', '!=', 'resolved')
                    ->when($scope, fn ($q) => $q->whereIn('assigned_to', $scope)->orWhereIn('raised_by', $scope))
                    ->count(),
                'route' => 'workspace.exceptions.index',
            ] : null,
        ];
    }

    public function customise(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.customise'), 403);

        $personal = UserDashboardLayout::where('user_id', $user->id)->get()->keyBy('widget_key');

        return view('workspace.customise', [
            'widgets' => $this->widgetCards($request, $this->roleLocks($user), $personal),
        ]);
    }

    public function save(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.customise'), 403);

        $data = $request->validate([
            'layouts' => ['required', 'array'],
            'layouts.*.widget_key' => ['required', 'string', 'exists:dashboard_widgets,key'],
            'layouts.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layouts.*.y' => ['required', 'integer', 'min:0', 'max:100'],
            'layouts.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layouts.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'layouts.*.visible' => ['boolean'],
        ]);

        $allowed = $this->access->widgetsFor($user)->pluck('key');
        $locks = $this->roleLocks($user);

        foreach ($data['layouts'] as $layout) {
            abort_unless($allowed->contains($layout['widget_key']), 403);

            $lock = $locks[$layout['widget_key']] ?? null;

            // A role-level lock always wins over whatever the client sent —
            // this is the actual enforcement point, the UI hiding drag
            // handles on locked cards is just a courtesy, not the boundary.
            if ($lock && $lock['locked']) {
                continue;
            }

            if ($lock && $lock['mandatory']) {
                $layout['visible'] = true;
            }

            UserDashboardLayout::updateOrCreate(
                ['user_id' => $user->id, 'widget_key' => $layout['widget_key']],
                $layout + ['config_json' => null]
            );
        }

        $this->access->audit($user, 'workspace.layout.saved', $user, null, $data);

        return back()->with('status', 'Workspace layout saved.');
    }

    /**
     * Locks are defined per role, not per person — a Head/Manager/Executive
     * profile inherits whatever their role says is locked/mandatory, so an
     * individual profile with no role-level restriction gets full drag
     * rights, while one under a locked role template doesn't.
     *
     * @return array<string, array{locked: bool, mandatory: bool}>
     */
    private function roleLocks(\App\Models\User $user): array
    {
        $roleIds = $this->access->activeRoles($user)->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        return AccessRoleDashboardLayout::whereIn('role_id', $roleIds)
            ->get()
            ->groupBy('widget_key')
            ->map(fn ($rows) => [
                // Any role granting this user access to the widget locking it is enough to lock it for them.
                'locked' => $rows->contains(fn ($row) => (bool) $row->locked),
                'mandatory' => $rows->contains(fn ($row) => (bool) $row->mandatory),
            ])
            ->all();
    }

    private function widgetCards(Request $request, array $locks, $personal)
    {
        return $this->access->widgetsFor($request->user())->map(function ($widget) use ($request, $locks, $personal) {
            $provider = app($widget->provider_class);
            $layout = $personal->get($widget->key);

            return [
                'widget' => $widget,
                'data' => $provider->data($request->user()),
                'layout' => $layout ? ['x' => $layout->x, 'y' => $layout->y, 'w' => $layout->w, 'h' => $layout->h, 'visible' => $layout->visible] : null,
                'locks' => $locks[$widget->key] ?? ['locked' => false, 'mandatory' => false],
            ];
        });
    }
}
