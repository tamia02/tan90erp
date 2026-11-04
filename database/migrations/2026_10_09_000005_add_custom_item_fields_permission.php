<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Lets Super Admin hand "manage custom item fields" to a Store Manager
// (Access Control > People > Extra access) without making them admin.
return new class extends Migration
{
    public function up(): void
    {
        $permission = collect(app(PermissionRegistry::class)->permissions())->firstWhere('key', 'inventory.custom_fields.manage');
        $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types']);
        DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('access_permissions')->where('key', 'inventory.custom_fields.manage')->delete();
    }
};
