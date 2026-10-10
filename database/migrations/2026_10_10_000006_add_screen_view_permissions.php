<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Super Admin can now give any staff screen (Inventory, PO, Guard, Unloading,
// Finance, Admin...) to a custom access role. Rows only - nobody gets access
// until a role ticks Allow; job roles keep working exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        $keys = array_column(PermissionRegistry::SCREEN_PAGES, 0);
        foreach (collect(app(PermissionRegistry::class)->permissions())->whereIn('key', $keys) as $permission) {
            $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types']);
            DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('access_permissions')->whereIn('key', array_column(PermissionRegistry::SCREEN_PAGES, 0))->delete();
    }
};
