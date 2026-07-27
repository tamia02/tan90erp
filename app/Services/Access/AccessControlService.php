<?php

namespace App\Services\Access;

use App\Access\AccessDecision;
use App\Access\RequestedGrant;
use App\Models\Access\AccessAuditLog;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Access\AccessUserRoleAssignment;
use App\Models\Access\DashboardWidgetCatalog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class AccessControlService
{
    public const SCOPES = ['self', 'created_by_me', 'assigned', 'direct_reports', 'team', 'unit', 'vertical', 'plant', 'location', 'warehouse', 'vendor', 'all'];

    public function hasNewAccess(User $user): bool
    {
        return $user->access_mode !== 'legacy' && ($this->activeRoles($user)->isNotEmpty() || $this->activeAssignments($user)->isNotEmpty());
    }

    public function can(User $user, string $permissionKey, mixed $record = null): bool
    {
        return $this->explain($user, $permissionKey, $record)->allowed;
    }

    public function explain(User $user, string $permissionKey, mixed $record = null): AccessDecision
    {
        if (! $user->is_active) {
            return new AccessDecision(false, 'user', reason: 'inactive user');
        }

        if ($user->super_admin && $user->access_mode !== 'legacy') {
            $this->audit($user, 'super_admin.bypass', null, null, ['permission' => $permissionKey]);

            return new AccessDecision(true, 'super_admin', 'all', reason: 'super admin bypass');
        }

        if ($user->access_mode === 'legacy') {
            return new AccessDecision(false, 'legacy', reason: 'advanced engine disabled for legacy user');
        }

        $permissions = $this->effectivePermissions($user);

        if (! isset($permissions[$permissionKey]) || ! $permissions[$permissionKey]['allowed']) {
            return new AccessDecision(false, 'advanced', reason: 'missing permission or explicit deny');
        }

        if ($permissions[$permissionKey]['scope_type'] === 'all') {
            return new AccessDecision(true, $permissions[$permissionKey]['source'], 'all', $permissions[$permissionKey]['field_mode'] ?? null, $permissions[$permissionKey]['locked'] ?? false);
        }

        $allowed = $record === null || $this->recordWithinScope($user, $permissions[$permissionKey], $record);

        return new AccessDecision($allowed, $permissions[$permissionKey]['source'], $permissions[$permissionKey]['scope_type'], $permissions[$permissionKey]['field_mode'] ?? null, $permissions[$permissionKey]['locked'] ?? false, $allowed ? 'ok' : 'record outside effective scope');
    }

    public function effectivePermissions(User $user): array
    {
        return Cache::remember("access:permissions:{$user->id}", 300, function () use ($user) {
            $effective = [];

            foreach ($this->activeRoles($user)->merge($this->activeAssignments($user)->pluck('role')) as $role) {
                foreach ($role->permissions as $permission) {
                    $effect = $permission->pivot->effect ?? ($permission->pivot->allowed ? 'allow' : 'inherit');
                    if ($effect === 'deny') {
                        $effective[$permission->key] = [
                            'permission' => $permission,
                            'allowed' => false,
                            'delegable' => false,
                            'scope_type' => $permission->pivot->max_scope_type ?? $permission->pivot->scope_type ?? 'self',
                            'scope_json' => null,
                            'field_mode' => $permission->pivot->field_mode,
                            'locked' => (bool) ($permission->pivot->locked ?? false),
                            'source' => 'role_deny:'.$role->code,
                        ];

                        continue;
                    }
                    if ($effect !== 'allow' && ! $permission->pivot->allowed) {
                        continue;
                    }

                    $current = $effective[$permission->key] ?? null;
                    $candidate = [
                        'permission' => $permission,
                        'allowed' => true,
                        'delegable' => (bool) $permission->pivot->delegable,
                        'scope_type' => $permission->pivot->max_scope_type ?? $permission->pivot->scope_type ?? 'self',
                        'scope_json' => $permission->pivot->scope_constraints_json ? json_decode($permission->pivot->scope_constraints_json, true) : ($permission->pivot->scope_json ? json_decode($permission->pivot->scope_json, true) : null),
                        'field_mode' => $permission->pivot->field_mode,
                        'locked' => (bool) ($permission->pivot->locked ?? false),
                        'source' => 'role:'.$role->code,
                    ];

                    if (! $current || (! $current['allowed']) || $this->scopeRank($candidate['scope_type']) > $this->scopeRank($current['scope_type'])) {
                        $effective[$permission->key] = $candidate;
                    } else {
                        $effective[$permission->key]['delegable'] = $effective[$permission->key]['delegable'] || $candidate['delegable'];
                    }
                }
            }

            foreach ($this->activeOverrides($user) as $override) {
                $permission = $override->permission ?? AccessPermission::find($override->permission_id);
                if (! $permission) {
                    continue;
                }
                $effective[$permission->key] = [
                    'permission' => $permission,
                    'allowed' => $override->effect === 'allow' || $override->allowed,
                    'delegable' => false,
                    'scope_type' => $override->scope_type ?? 'self',
                    'scope_json' => $override->scope_constraints_json ?? $override->scope_json,
                    'field_mode' => $override->field_mode,
                    'locked' => false,
                    'source' => 'direct_user_override',
                ];
            }

            return $effective;
        });
    }

    public function effectiveScope(User $user, string $permissionKey): ?array
    {
        return $this->effectivePermissions($user)[$permissionKey] ?? null;
    }

    public function canDelegate(User $actor, string $permissionKey, array|RequestedGrant $requestedScope): bool
    {
        $permission = $this->effectivePermissions($actor)[$permissionKey] ?? null;

        if (! $permission || ! $permission['delegable']) {
            return false;
        }

        $requestedType = $requestedScope instanceof RequestedGrant ? $requestedScope->scopeType : ($requestedScope['scope_type'] ?? 'self');

        return $this->scopeRank($requestedType) <= $this->scopeRank($permission['scope_type']);
    }

    public function canManageRole(User $actor, AccessRole $role): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        $actorLevel = $this->highestLevel($actor);
        $actorVerticalIds = $this->activeRoles($actor)->pluck('vertical_id')
            ->merge($this->activeAssignments($actor)->pluck('vertical_id'))
            ->filter()
            ->unique();
        $roleLevel = $role->hierarchy_level ?? $role->level;

        if ($actorLevel === AccessRole::LEVEL_HEAD) {
            return $roleLevel === AccessRole::LEVEL_MANAGER
                && $role->vertical_id
                && $actorVerticalIds->contains($role->vertical_id);
        }

        if ($actorLevel === AccessRole::LEVEL_MANAGER) {
            return $roleLevel === AccessRole::LEVEL_EXECUTIVE
                && $role->vertical_id
                && $actorVerticalIds->contains($role->vertical_id);
        }

        return false;
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        $actorLevel = $this->highestLevel($actor);
        $targetRoles = $this->activeRoles($target);

        if ($targetRoles->isEmpty()) {
            return false;
        }

        return $targetRoles->every(fn (AccessRole $role) => $this->canManageRole($actor, $role));
    }

    /**
     * This user plus everyone who reports up to them, directly or through
     * another manager in between (e.g. a Head's reports include their
     * Managers' Executives too). Depth-bounded well past this app's 4
     * hierarchy levels purely as a cycle guard.
     *
     * @return Collection<int, int>
     */
    public function reportUserIds(User $user): Collection
    {
        $ids = collect([$user->id]);
        $frontier = collect([$user->id]);

        for ($i = 0; $i < 10 && $frontier->isNotEmpty(); $i++) {
            $next = AccessPosition::where('status', 'active')
                ->whereIn('reports_to_user_id', $frontier)
                ->pluck('user_id')
                ->diff($ids);

            if ($next->isEmpty()) {
                break;
            }

            $ids = $ids->merge($next);
            $frontier = $next;
        }

        return $ids->unique()->values();
    }

    /**
     * User IDs a dashboard widget should scope its counts to - null means
     * "no filter, show the company-wide total" (super admins only).
     * Everyone else sees themselves plus whoever reports up to them, so an
     * individual contributor sees their own numbers and a Head/Manager sees
     * their whole team's combined total.
     *
     * @return Collection<int, int>|null
     */
    public function teamScopedUserIds(User $user): ?Collection
    {
        if ($user->super_admin) {
            return null;
        }

        return $this->reportUserIds($user);
    }

    public function widgetsFor(User $user): Collection
    {
        return DashboardWidgetCatalog::query()
            ->where('status', 'active')
            ->orderBy('module')
            ->orderBy('title')
            ->get()
            ->filter(fn (DashboardWidgetCatalog $widget) => $this->can($user, $widget->permission_key))
            ->values();
    }

    public function navigationFor(User $user): array
    {
        return AccessPermission::query()
            ->where('status', 'active')
            ->whereNotNull('route_name')
            ->whereIn('category', ['module', 'navigation', 'page', 'action'])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (AccessPermission $permission) => str_ends_with($permission->key, '.view') && $this->can($user, $permission->key))
            ->map(fn (AccessPermission $permission) => ['label' => $permission->label, 'route' => $permission->route_name, 'icon' => 'shield'])
            ->values()
            ->all();
    }

    public function activeAssignments(User $user): Collection
    {
        return AccessUserRoleAssignment::with('role.permissions')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    public function activeOverrides(User $user): Collection
    {
        return AccessUserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    public function permissionForRoute(?string $routeName): ?AccessPermission
    {
        return $routeName ? AccessPermission::where('route_name', $routeName)->first() : null;
    }

    public function audit(?User $actor, string $action, mixed $target = null, ?array $before = null, ?array $after = null): void
    {
        AccessAuditLog::create([
            'actor_id' => $actor?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'target_type' => is_object($target) ? $target::class : null,
            'target_id' => is_object($target) && method_exists($target, 'getKey') ? $target->getKey() : null,
            'before_json' => $before,
            'after_json' => $after,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);

        if ($actor) {
            $this->flushUser($actor);
        }
    }

    public function flushUser(User $user): void
    {
        Cache::forget("access:permissions:{$user->id}");
    }

    public function activeRoles(User $user): Collection
    {
        return $user->accessRoles()
            ->with(['permissions', 'vertical'])
            ->where('access_roles.status', 'active')
            ->wherePivot('status', 'active')
            ->where(function ($query) {
                $query->whereNull('access_user_roles.starts_at')->orWhere('access_user_roles.starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('access_user_roles.expires_at')->orWhere('access_user_roles.expires_at', '>', now());
            })
            ->get();
    }

    private function isSuperAdmin(User $user): bool
    {
        return $this->activeRoles($user)->merge($this->activeAssignments($user)->pluck('role'))->contains(fn (AccessRole $role) => ($role->hierarchy_level ?? $role->level) === AccessRole::LEVEL_SUPER_ADMIN);
    }

    private function highestLevel(User $user): ?int
    {
        return AccessPosition::where('user_id', $user->id)->where('status', 'active')->where('is_primary', true)->value('hierarchy_level')
            ?? $this->activeRoles($user)->merge($this->activeAssignments($user)->pluck('role'))->min(fn (AccessRole $role) => $role->hierarchy_level ?? $role->level);
    }

    private function scopeRank(string $scope): int
    {
        return array_search($scope, self::SCOPES, true) ?: 0;
    }

    private function recordWithinScope(User $user, array $permission, mixed $record): bool
    {
        if ($permission['scope_type'] === 'self') {
            return isset($record->user_id) && (int) $record->user_id === (int) $user->id;
        }

        if ($permission['scope_type'] === 'assigned') {
            return isset($record->assigned_to) && (int) $record->assigned_to === (int) $user->id;
        }

        if ($permission['scope_type'] === 'team') {
            $teamIds = AccessTeam::where('manager_user_id', $user->id)
                ->pluck('id')
                ->merge($user->accessRoles()->pluck('access_roles.id'));

            return isset($record->team_id) && $teamIds->contains($record->team_id);
        }

        if ($permission['scope_type'] === 'vertical') {
            $verticalIds = $this->activeRoles($user)->pluck('vertical_id')->filter()->unique();

            return isset($record->vertical_id) && $verticalIds->contains($record->vertical_id);
        }

        if ($permission['scope_type'] === 'direct_reports') {
            return isset($record->created_by) && $this->reportUserIds($user)->contains((int) $record->created_by);
        }

        return false;
    }
}
