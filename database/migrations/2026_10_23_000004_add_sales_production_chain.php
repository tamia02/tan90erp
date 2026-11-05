<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_fg_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('tan90_locations');
            $table->foreignId('finished_good_id')->constrained('tan90_finished_goods');
            $table->foreignId('inventory_fg_item_id')->constrained('inventory_items');
            $table->foreignId('rm_warehouse_id')->constrained('tan90_warehouses');
            $table->foreignId('fg_warehouse_id')->constrained('tan90_warehouses');
            $table->foreignId('reusable_pcm_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'finished_good_id']);
        });
        Schema::table('flow_customer_orders', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->foreignId('fg_warehouse_id')->nullable()->constrained('tan90_warehouses')->nullOnDelete();
        });
        Schema::table('flow_order_lines', fn (Blueprint $table) => $table->foreignId('inventory_fg_item_id')->nullable()->constrained('inventory_items')->nullOnDelete());
        Schema::table('forge_production_plans', fn (Blueprint $table) => $table->foreignId('source_order_line_id')->nullable()->constrained('flow_order_lines')->nullOnDelete());
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->unsignedTinyInteger('workflow_version')->default(1));
        Schema::create('forge_material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders');
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handover_started_at')->nullable();
            $table->timestamp('handover_ended_at')->nullable();
            $table->unsignedInteger('manpower')->nullable();
            $table->timestamps();
        });
        Schema::create('forge_material_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained('forge_material_requests')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->decimal('qty', 14, 3);
            $table->text('extra_reason')->nullable();
            $table->timestamps();
            $table->unique(['material_request_id', 'inventory_item_id'], 'forge_request_item_unique');
        });
        Schema::table('forge_material_issues', fn (Blueprint $table) => $table->foreignId('material_request_line_id')->nullable()->constrained('forge_material_request_lines')->nullOnDelete());
        Schema::create('forge_bundle_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained('forge_work_orders');
            $table->foreignId('final_qc_result_id')->unique()->constrained('forge_final_qc_results');
            $table->unsignedInteger('bundle_count')->default(0);
            $table->decimal('units_per_bundle', 14, 3)->default(0);
            $table->decimal('loose_units', 14, 3)->default(0);
            $table->decimal('defective_qty', 14, 3)->default(0);
            $table->string('defective_disposition', 30);
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('manpower');
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('forge_bundle_material_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_record_id')->constrained('forge_bundle_records')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->decimal('used_qty', 14, 3);
            $table->decimal('scrap_qty', 14, 3);
            $table->decimal('return_qty', 14, 3);
            $table->timestamps();
            $table->unique(['bundle_record_id', 'inventory_item_id'], 'forge_bundle_item_unique');
        });
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->foreignId('bin_id')->nullable()->constrained('tan90_bins')->nullOnDelete());
        Schema::create('flow_handling_unit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handling_unit_id')->constrained('flow_handling_units')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('flow_order_lines');
            $table->decimal('qty', 14, 3);
            $table->timestamps();
            $table->unique(['handling_unit_id', 'order_line_id']);
        });
        Schema::table('workspace_tasks', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->string('target_permission', 100)->nullable();
            $table->string('event_key', 150)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_tasks', fn (Blueprint $table) => $table->dropColumn(['site_id', 'target_permission', 'event_key']));
        Schema::dropIfExists('flow_handling_unit_lines');
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->dropConstrainedForeignId('bin_id'));
        Schema::dropIfExists('forge_bundle_material_lines');
        Schema::dropIfExists('forge_bundle_records');
        Schema::table('forge_material_issues', fn (Blueprint $table) => $table->dropConstrainedForeignId('material_request_line_id'));
        Schema::dropIfExists('forge_material_request_lines');
        Schema::dropIfExists('forge_material_requests');
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropColumn('workflow_version'));
        Schema::table('forge_production_plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_order_line_id'));
        Schema::table('flow_order_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_fg_item_id'));
        Schema::table('flow_customer_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fg_warehouse_id');
            $table->dropConstrainedForeignId('site_id');
        });
        Schema::dropIfExists('production_fg_mappings');
    }
};
