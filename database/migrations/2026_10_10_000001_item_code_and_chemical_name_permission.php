<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Items: SKU Code + SKU Name (the real chemical name, masked) + Display Name.
// Only Super Admin, or people given "See real chemical names", see SKU Name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('code', 80)->nullable()->after('sku');
        });

        $permission = collect(app(PermissionRegistry::class)->permissions())->firstWhere('key', 'inventory.chemical_names.view');
        $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types']);
        DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('access_permissions')->where('key', 'inventory.chemical_names.view')->delete();
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
