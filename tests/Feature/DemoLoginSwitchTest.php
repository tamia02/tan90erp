<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 2026-10-12: production copy has no password-less demo logins (DEMO_LOGINS=false);
// the demo/staging site keeps them (default on).
class DemoLoginSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_logins_on_by_default(): void
    {
        User::factory()->create(['role' => Role::Admin]);
        $this->get('/login')->assertOk()->assertSee('Open your role workspace');
        $this->get(route('role-login', 'admin'))->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_switched_off_only_email_and_password_remain(): void
    {
        config(['app.demo_logins' => false]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->get('/login')->assertOk()->assertDontSee('Open your role workspace')->assertDontSee('demo123')->assertSee('Email');
        $this->get('/role-login/admin')->assertNotFound();
        $this->get('/demo-user-login/'.$admin->id)->assertNotFound();
        $this->assertGuest();
    }
}
