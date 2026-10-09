<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Client 2026-10-10: the team must be able to add fields to any table
// themselves (pick the table, name the field, choose the data type), so the
// rollout isn't held up waiting for development. One definitions table for
// every table; values for Master Data records live in custom_field_values.
// The Inventory Items fields made on 2026-10-09 move in here (entity
// 'inventory_items'); their values stay on inventory_items.custom_fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 80);
            $table->string('field_key', 60);
            $table->string('label');
            $table->string('input_type', 20)->default('text');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entity', 'field_key']);
            $table->index(['entity', 'active']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 80);
            $table->unsignedBigInteger('record_id');
            $table->json('data');
            $table->timestamps();
            $table->unique(['entity', 'record_id']);
        });

        if (Schema::hasTable('item_custom_fields')) {
            foreach (DB::table('item_custom_fields')->get() as $row) {
                DB::table('custom_field_definitions')->insertOrIgnore([
                    'entity' => 'inventory_items', 'field_key' => $row->field_key, 'label' => $row->label, 'input_type' => $row->input_type,
                    'options' => $row->options, 'required' => $row->required, 'active' => $row->active, 'sort_order' => $row->sort_order,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                ]);
            }
        }

        $permission = collect(app(PermissionRegistry::class)->permissions())->firstWhere('key', 'custom_fields.manage');
        $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types']);
        DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('access_permissions')->where('key', 'custom_fields.manage')->delete();
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
