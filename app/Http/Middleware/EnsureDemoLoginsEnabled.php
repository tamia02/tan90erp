<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Password-less demo logins exist only where DEMO_LOGINS is on (staging/demo).
// On production they are 404, so nobody can sign in by typing /role-login/admin.
class EnsureDemoLoginsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('app.demo_logins'), 404);

        return $next($request);
    }
}
