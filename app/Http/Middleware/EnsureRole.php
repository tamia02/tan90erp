<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Replaces the React prototype's RequireRole component. Admin is the only
// role that can see into another module — every other role is walled off,
// per the client's "strict portal isolation" requirement. Unlike the React
// version's blocking "log out first" screen, a real Laravel session can
// only ever hold one authenticated user/role at a time, so there's no
// cross-role state to guard against here beyond this check.
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        // A Tan90-only user (Master Data/BOM, no GRN role) has role === null
        // since that column became nullable in the Tan90 merge - treat that
        // the same as any other role mismatch instead of erroring on
        // null->value.
        abort_unless(
            $user && $user->role && ($user->role === Role::Admin || $user->role->value === $role),
            403,
            'You do not have access to this module.',
        );

        return $next($request);
    }
}
