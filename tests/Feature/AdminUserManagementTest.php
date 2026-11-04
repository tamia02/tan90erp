<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_a_created_user_and_optional_password(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'super_admin' => true]);
        $person = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.test', 'role' => Role::Guard]);
        $this->actingAs($admin);

        Volt::test('admin.users')->call('editUser', $person->id)
            ->assertSet('editingId', $person->id)
            ->assertSee('Save user changes')
            ->set('name', 'New Name')
            ->set('email', 'new@example.test')
            ->set('phone', '555-0102')
            ->set('role', Role::StoreManager->value)
            ->set('password', 'A-new-password-123')
            ->call('updateUser')
            ->assertHasNoErrors();

        $person->refresh();
        $this->assertSame('New Name', $person->name);
        $this->assertSame('new@example.test', $person->email);
        $this->assertSame(Role::StoreManager, $person->role);
        $this->assertTrue(Hash::check('A-new-password-123', $person->password));
        $this->assertSame(1, AuditLogEntry::where('action', 'like', 'User updated%')->count());
    }

    public function test_super_admin_audit_page_and_export_support_search_filters(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'super_admin' => true]);
        AuditLogEntry::create(['action' => 'GRN posted', 'detail' => 'PO-00005 received']);
        AuditLogEntry::create(['action' => 'Other event', 'detail' => 'Unrelated']);
        $this->actingAs($admin);

        $this->get(route('admin.audit-logs', ['q' => 'PO-00005']))
            ->assertOk()->assertSee('Audit Logs')->assertSee('GRN posted')->assertDontSee('Other event');

        $response = $this->get(route('admin.audit-logs.export', ['q' => 'PO-00005']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('PO-00005 received', $response->streamedContent());
        $this->assertStringNotContainsString('Unrelated', $response->streamedContent());
    }

    public function test_non_admin_cannot_open_super_admin_audit_logs(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Guard]))
            ->get(route('admin.audit-logs'))->assertForbidden();
    }
}
