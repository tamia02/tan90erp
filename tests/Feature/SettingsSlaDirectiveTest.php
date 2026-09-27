<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Reproduces the reported bug: SLA directive was self-service on the
 * personal Settings page — any account, including a vendor, could set
 * their own SLA tier, undermining the point of an SLA. It's already
 * Super-Admin-controlled via admin.users; this just removes the second,
 * self-service path so it can't be overridden by the account owner.
 */
class SettingsSlaDirectiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_change_their_own_sla_directive_from_settings(): void
    {
        $user = User::factory()->create(['sla_directive' => 'standard_24h']);
        $this->actingAs($user);

        Volt::test('shared.settings')
            ->assertSee('Set by your Super Admin')
            ->call('save');

        $this->assertSame('standard_24h', $user->fresh()->sla_directive);
    }
}
