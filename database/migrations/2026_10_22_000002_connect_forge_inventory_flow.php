<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_work_orders', function (Blueprint $table): void {
            $table->foreignId('inventory_fg_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('reusable_pcm_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->foreignId('rm_warehouse_id')->nullable()->constrained('tan90_warehouses')->nullOnDelete();
            $table->foreignId('fg_warehouse_id')->nullable()->constrained('tan90_warehouses')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('source_key', 120)->nullable()->unique();
        });
        Schema::table('forge_material_issues', function (Blueprint $table): void {
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->unique()->constrained('inventory_movements')->nullOnDelete();
        });
        Schema::create('forge_material_fifo_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_issue_id')->constrained('forge_material_issues')->cascadeOnDelete();
            $table->string('source_type', 20); // opening, grn, movement
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('qty', 14, 3);
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
        Schema::create('forge_material_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->timestamps();
            $table->unique(['work_order_id', 'inventory_item_id'], 'forge_material_reservation_unique');
        });
        Schema::create('forge_mixing_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->string('mixing_type', 40)->default('manual');
            $table->decimal('chemical_qty', 14, 3);
            $table->decimal('water_qty', 14, 3);
            $table->decimal('pcm_qty', 14, 3);
            $table->decimal('wastage_qty', 14, 3)->default(0);
            $table->unsignedInteger('manpower')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('forge_process_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->string('step', 20); // filling, sealing
            $table->foreignId('machine_id')->nullable()->constrained('forge_machines')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->decimal('good_qty', 14, 3)->default(0);
            $table->decimal('rejected_qty', 14, 3)->default(0);
            $table->decimal('wastage_qty', 14, 3)->default(0);
            $table->unsignedInteger('manpower')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('forge_final_qc_results', function (Blueprint $table): void {
            $table->string('leak_test', 20)->nullable();
            $table->string('pressure_test', 20)->nullable();
            $table->decimal('recovered_pcm_qty', 14, 3)->default(0);
        });
        Schema::table('forge_batches', function (Blueprint $table): void {
            $table->foreignId('inventory_movement_id')->nullable()->unique()->constrained('inventory_movements')->nullOnDelete();
        });
        Schema::table('flow_inventory_lots', function (Blueprint $table): void {
            $table->unique('forge_batch_id', 'flow_inventory_lots_forge_batch_unique');
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('tan90_warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->dropUnique('flow_inventory_lots_forge_batch_unique'));
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_id'));
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->dropConstrainedForeignId('location_id'));
        Schema::table('flow_inventory_lots', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_item_id'));
        Schema::table('forge_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_movement_id'));
        Schema::table('forge_final_qc_results', fn (Blueprint $table) => $table->dropColumn(['leak_test', 'pressure_test', 'recovered_pcm_qty']));
        Schema::dropIfExists('forge_process_records');
        Schema::dropIfExists('forge_mixing_records');
        Schema::dropIfExists('forge_material_reservations');
        Schema::dropIfExists('forge_material_fifo_allocations');
        Schema::table('forge_material_issues', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_movement_id'));
        Schema::table('forge_material_issues', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_item_id'));
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropColumn('source_key'));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('fg_warehouse_id'));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('rm_warehouse_id'));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('site_id'));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('reusable_pcm_item_id'));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_fg_item_id'));
    }
};
