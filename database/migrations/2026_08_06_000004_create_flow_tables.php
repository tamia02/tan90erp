<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Space 06 (Flow) — Warehouse, Orders & Delivery. Consumes Forge's
// finished_batch.released event (forge_batches) to create sellable
// inventory; never touches raw-material stock (Origin) or production
// (Forge) directly. Inventory truth is append-only movements
// (flow_inventory_movements), balances live on flow_inventory_lots.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finished_good_id')->constrained('tan90_finished_goods');
            $table->foreignId('forge_batch_id')->nullable()->constrained('forge_batches')->nullOnDelete();
            $table->string('lot_number');
            $table->string('warehouse')->default('Bhiwandi FG Warehouse');
            $table->string('zone')->nullable();
            $table->string('bin')->nullable();
            $table->decimal('qty_received', 14, 3);
            $table->decimal('qty_available', 14, 3);
            $table->decimal('qty_allocated', 14, 3)->default(0);
            $table->decimal('qty_picked', 14, 3)->default(0);
            $table->string('uom', 20);
            $table->string('quality_status', 20)->default('released'); // released, hold
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('staged'); // staged, available, depleted, blocked
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('flow_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('flow_inventory_lots')->cascadeOnDelete();
            $table->string('movement_type', 30); // fg_receipt, putaway, allocation, deallocation, pick, pack, dispatch, customer_return, restock, scrap, adjustment
            $table->decimal('qty', 14, 3);
            $table->string('warehouse')->nullable();
            $table->string('zone')->nullable();
            $table->string('bin')->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('flow_customer_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('customer_name');
            $table->string('destination')->nullable();
            $table->string('temperature_requirement', 20)->nullable(); // ambient, chilled, frozen
            $table->unsignedInteger('min_shelf_life_days')->default(0);
            $table->date('requested_date')->nullable();
            // draft, validated, released, atp_confirmed, atp_partial, allocated, waved,
            // picking, picked, packing, packed, dispatch_planned, loading, in_transit,
            // pod_received, delivered, closed, on_hold, cancelled
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('flow_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('flow_customer_orders')->cascadeOnDelete();
            $table->foreignId('finished_good_id')->constrained('tan90_finished_goods');
            $table->decimal('qty_ordered', 14, 3);
            $table->decimal('qty_allocated', 14, 3)->default(0);
            $table->decimal('qty_picked', 14, 3)->default(0);
            $table->decimal('qty_packed', 14, 3)->default(0);
            $table->string('uom', 20);
            $table->timestamps();
        });

        Schema::create('flow_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained('flow_order_lines')->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('flow_inventory_lots');
            $table->decimal('qty', 14, 3);
            $table->string('status', 20)->default('reserved'); // reserved, picked, deallocated
            $table->timestamps();
        });

        Schema::create('flow_picking_waves', function (Blueprint $table) {
            $table->id();
            $table->string('wave_number')->unique();
            $table->string('warehouse')->nullable();
            $table->string('status', 20)->default('draft'); // draft, published, picking, completed
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_pick_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wave_id')->constrained('flow_picking_waves')->cascadeOnDelete();
            $table->foreignId('allocation_id')->constrained('flow_allocations');
            $table->decimal('qty_to_pick', 14, 3);
            $table->decimal('qty_picked', 14, 3)->default(0);
            $table->string('status', 20)->default('pending'); // pending, picked, short
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_number')->unique();
            $table->string('warehouse')->nullable();
            $table->string('dock_number')->nullable();
            $table->string('transporter')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('temperature_logger_id')->nullable();
            $table->string('seal_number')->nullable();
            $table->string('status', 20)->default('planned'); // planned, loading, released, in_transit, delivered, closed
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_handling_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('flow_customer_orders')->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('flow_shipments')->nullOnDelete();
            $table->string('hu_number')->unique();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->string('status', 20)->default('packing'); // packing, packed, sealed
            $table->timestamp('sealed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_temperature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('flow_shipments')->cascadeOnDelete();
            $table->decimal('reading_celsius', 6, 2);
            $table->boolean('excursion')->default(false);
            $table->string('disposition', 20)->nullable(); // release, customer_deviation, return_to_warehouse
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
        });

        Schema::create('flow_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('flow_shipments')->cascadeOnDelete();
            $table->foreignId('customer_order_id')->constrained('flow_customer_orders');
            $table->string('receiver_name')->nullable();
            $table->decimal('qty_accepted', 14, 3)->nullable();
            $table->text('exception_notes')->nullable();
            $table->string('pod_reference')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_returns', function (Blueprint $table) {
            $table->id();
            $table->string('rma_number')->unique();
            $table->foreignId('customer_order_id')->constrained('flow_customer_orders');
            $table->string('reason');
            $table->decimal('qty', 14, 3);
            $table->string('uom', 20);
            // requested, received, inspected, dispositioned, closed
            $table->string('status', 20)->default('requested');
            $table->string('disposition', 20)->nullable(); // restock, rework, scrap, reject
            $table->text('inspection_notes')->nullable();
            $table->boolean('claim_raised')->default(false);
            $table->decimal('claim_amount', 12, 2)->nullable();
            $table->string('claim_status', 20)->nullable(); // pending, approved, rejected, settled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_returns');
        Schema::dropIfExists('flow_deliveries');
        Schema::dropIfExists('flow_temperature_events');
        Schema::dropIfExists('flow_handling_units');
        Schema::dropIfExists('flow_shipments');
        Schema::dropIfExists('flow_pick_tasks');
        Schema::dropIfExists('flow_picking_waves');
        Schema::dropIfExists('flow_allocations');
        Schema::dropIfExists('flow_order_lines');
        Schema::dropIfExists('flow_customer_orders');
        Schema::dropIfExists('flow_inventory_movements');
        Schema::dropIfExists('flow_inventory_lots');
    }
};
