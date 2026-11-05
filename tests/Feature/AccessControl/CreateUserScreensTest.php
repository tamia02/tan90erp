<?php

namespace Tests\Feature\AccessControl;

use App\Enums\Role;
use App\Models\Access\AccessRole;
use App\Models\User;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Both places a Super Admin adds a person must give a working login that
// lands on the right dashboard.
class CreateUserScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_screen_creates_a_user_with_job_role_extra_role_and_access_role(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::where('email', 'superadmin@tan90.demo')->firstOrFail();
        $role = AccessRole::where('code', 'ACCESS-GRN-EXEC')->firstOrFail();

        $this->actingAs($admin)->post(route('access.people.store'), [
            'name' => 'New Executive', 'email' => 'new.exec@tan90.local', 'password' => 'Test@12345',
            'role_id' => $role->id, 'main_role' => 'storeExec', 'extra_roles' => ['qc'],
        ])->assertRedirect();

        $user = User::where('email', 'new.exec@tan90.local')->firstOrFail();
        $this->assertSame(Role::StoreExec, $user->role);
        $this->assertTrue(Hash::check('Test@12345', $user->password));
        $this->assertTrue($user->holdsRole(Role::Qc));
        $this->assertTrue($user->accessRoles()->whereKey($role->id)->exists());

        // Their own login works and lands on their dashboard with both menus.
        auth()->logout();
        $this->post('/logout');
        $this->actingAs($user->fresh())->get(route('unloading.dashboard'))->assertOk()->assertSee('QC Queue');
        $this->actingAs($user->fresh())->get(route('qc.queue'))->assertOk();
        $this->actingAs($user->fresh())->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_admin_users_screen_creates_a_working_user(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin);
        Volt::test('admin.users')
            ->set('name', 'Gate Guard Two')->set('email', 'guard.two@tan90.local')->set('role', 'guard')->set('password', 'Test@12345')
            ->call('addUser')->assertHasNoErrors();

        $guard = User::where('email', 'guard.two@tan90.local')->firstOrFail();
        $this->assertSame(Role::Guard, $guard->role);
        $this->actingAs($guard)->get(route('guard.dashboard'))->assertOk();
        $this->actingAs($guard)->get(route('inventory.stock'))->assertForbidden();
    }
}
