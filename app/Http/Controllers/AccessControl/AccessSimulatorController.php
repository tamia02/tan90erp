<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessPermission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

// Answers "why can/can't this person do that" step by step, using the same
// decision engine (AccessControlService::explain) the app itself checks on
// every request - not a separate simulated copy of the rules that could
// drift out of sync with reality.
class AccessSimulatorController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.simulator.view'), 403);

        $people = User::where('access_mode', 'advanced')->orderBy('name')->get();
        $permissions = AccessPermission::where('status', 'active')->orderBy('module')->orderBy('label')->get();

        $subject = $request->filled('user_id') ? User::find($request->integer('user_id')) : null;
        $permission = $request->filled('permission_key') ? AccessPermission::where('key', $request->string('permission_key'))->first() : null;

        return view('access-control.simulator', [
            'people' => $people,
            'permissions' => $permissions,
            'subject' => $subject,
            'permission' => $permission,
            'steps' => ($subject && $permission) ? $this->trace($subject, $permission) : null,
        ]);
    }

    /** @return array<int, array{label: string, detail: string, status: string}> */
    private function trace(User $subject, AccessPermission $permission): array
    {
        $steps = [];

        $steps[] = [
            'label' => 'Identity',
            'detail' => "{$subject->name} ({$subject->email}) - access mode: {$subject->access_mode}, active: ".($subject->is_active ? 'yes' : 'no'),
            'status' => $subject->is_active ? 'info' : 'deny',
        ];

        if (! $subject->is_active) {
            $steps[] = ['label' => 'Stopped here', 'detail' => 'Account is inactive - every permission is denied regardless of role.', 'status' => 'deny'];

            return $this->withFinal($steps, $this->access->explain($subject, $permission->key));
        }

        if ($subject->super_admin && $subject->access_mode !== 'legacy') {
            $steps[] = ['label' => 'Super admin bypass', 'detail' => 'This user is flagged super_admin, which grants every permission and skips role/scope checks entirely.', 'status' => 'allow'];

            return $this->withFinal($steps, $this->access->explain($subject, $permission->key));
        }

        if ($subject->access_mode === 'legacy') {
            $steps[] = ['label' => 'Legacy account', 'detail' => 'access_mode is "legacy" - the Access Control engine is disabled for this user; they only use their GRN role, not this permission system.', 'status' => 'deny'];

            return $this->withFinal($steps, $this->access->explain($subject, $permission->key));
        }

        $roles = $this->access->activeRoles($subject);
        if ($roles->isEmpty()) {
            $steps[] = ['label' => 'Roles held', 'detail' => 'No active Access Control roles assigned.', 'status' => 'info'];
        }
        foreach ($roles as $role) {
            $grant = $role->permissions->firstWhere('key', $permission->key);
            if (! $grant) {
                $steps[] = ['label' => "Role: {$role->name}", 'detail' => "Does not mention \"{$permission->label}\" - contributes nothing for this permission.", 'status' => 'info'];

                continue;
            }
            $effect = $grant->pivot->effect ?? ($grant->pivot->allowed ? 'allow' : 'inherit');
            $scope = $grant->pivot->max_scope_type ?? $grant->pivot->scope_type ?? 'self';
            $steps[] = [
                'label' => "Role: {$role->name}",
                'detail' => $effect === 'deny'
                    ? "Explicitly DENIES \"{$permission->label}\"."
                    : "Grants \"{$permission->label}\" at scope \"{$scope}\"".($grant->pivot->delegable ? ' (delegable to subordinates)' : '').'.',
                'status' => $effect === 'deny' ? 'deny' : 'allow',
            ];
        }

        $overrides = $this->access->activeOverrides($subject)->filter(fn ($o) => ($o->permission?->key ?? $o->permission_id) === $permission->key || $o->permission?->key === $permission->key);
        foreach ($overrides as $override) {
            $steps[] = [
                'label' => 'Direct override',
                'detail' => ($override->effect === 'allow' || $override->allowed ? 'Directly ALLOWS' : 'Directly DENIES')." \"{$permission->label}\" for this specific person"
                    .($override->reason ? " - reason: {$override->reason}" : '')
                    .($override->expires_at ? ". Expires {$override->expires_at->format('d M Y, H:i')}" : '.'),
                'status' => ($override->effect === 'allow' || $override->allowed) ? 'allow' : 'deny',
            ];
        }
        if ($overrides->isEmpty()) {
            $steps[] = ['label' => 'Direct overrides', 'detail' => 'None active for this permission - a direct override always wins over whatever the role says.', 'status' => 'info'];
        }

        $effective = $this->access->effectivePermissions($subject)[$permission->key] ?? null;
        if ($effective) {
            $steps[] = [
                'label' => 'Effective permission',
                'detail' => 'Winning source: '.$this->describeSource($effective['source'])." - scope \"{$effective['scope_type']}\"".($effective['locked'] ? ', locked (cannot be re-delegated further)' : '').'.',
                'status' => $effective['allowed'] ? 'allow' : 'deny',
            ];
        } else {
            $steps[] = ['label' => 'Effective permission', 'detail' => 'No role or override grants this permission at all.', 'status' => 'deny'];
        }

        return $this->withFinal($steps, $this->access->explain($subject, $permission->key));
    }

    private function describeSource(string $source): string
    {
        return match (true) {
            str_starts_with($source, 'role_deny:') => 'role deny ('.substr($source, 10).')',
            str_starts_with($source, 'role:') => 'role ('.substr($source, 5).')',
            $source === 'direct_user_override' => 'direct override on this person',
            default => $source,
        };
    }

    private function withFinal(array $steps, \App\Access\AccessDecision $decision): array
    {
        $steps[] = [
            'label' => 'Final decision',
            'detail' => ($decision->allowed ? 'ALLOWED' : 'DENIED').' - '.$decision->reason,
            'status' => $decision->allowed ? 'allow' : 'deny',
        ];

        return $steps;
    }
}
