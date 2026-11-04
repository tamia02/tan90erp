<?php

namespace Tests\Unit;

use App\Enums\Role;
use App\Http\Middleware\EnsureRole;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalSuperAdminIdentityTest extends TestCase
{
    public function test_advanced_super_admin_is_recognized_as_admin_across_legacy_modules(): void
    {
        $user = new User();
        $user->forceFill([
            'role' => null,
            'super_admin' => true,
            'access_mode' => 'advanced',
        ]);

        $this->assertTrue($user->isAdmin());
    }

    public function test_legacy_operational_users_do_not_inherit_super_admin_identity(): void
    {
        $user = new User();
        $user->forceFill([
            'role' => Role::StoreManager,
            'super_admin' => false,
            'access_mode' => 'advanced',
        ]);

        $this->assertFalse($user->isAdmin());
    }

    public function test_legacy_admin_enum_remains_compatible(): void
    {
        $user = new User();
        $user->forceFill([
            'role' => Role::Admin,
            'super_admin' => false,
            'access_mode' => 'legacy',
        ]);

        $this->assertTrue($user->isAdmin());
    }

    public function test_canonical_super_admin_passes_legacy_admin_route_middleware(): void
    {
        $user = new User();
        $user->forceFill([
            'role' => null,
            'super_admin' => true,
            'access_mode' => 'advanced',
        ]);
        $request = Request::create('/admin');
        $request->setUserResolver(fn () => $user);

        $response = app(EnsureRole::class)->handle($request, fn () => new Response('allowed'), 'admin');

        $this->assertSame('allowed', $response->getContent());
    }
}
