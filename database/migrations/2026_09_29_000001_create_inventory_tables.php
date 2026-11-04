<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inventory module (RM/PM -> production -> FG -> dispatch, plus trading).
// Only new tables: GRN receipts are *read* from ledger_entries (matched by
// the same SKU name) and never written, so the vendor -> GRN flow is untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique(); // same text as the GRN / PO line SKU
            $table->string('name')->nullable();
            $table->string('item_type', 16); // rm | pm | fg | trading
            $table->string('unit', 20)->default('NOS');
            $table->decimal('opening_qty', 14, 3)->default(0); // "current inventory" when tracking starts
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Recipe: how much of each RM/PM makes ONE unit of a finished good.
        Schema::create('inventory_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fg_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty_per_unit', 14, 4);
            $table->timestamps();
            $table->unique(['fg_item_id', 'component_item_id']);
        });

        Schema::create('inventory_productions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('fg_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty_produced', 14, 3);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->string('customer_name');
            $table->string('reference')->nullable(); // invoice / sales order no.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every stock change except GRN receipts. qty is always positive; the
        // type says which way it moves (produced = in; consumed/wastage/dispatched = out).
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('type', 16); // produced | consumed | wastage | dispatched
            $table->decimal('qty', 14, 3);
            $table->foreignId('production_id')->nullable()->constrained('inventory_productions')->nullOnDelete();
            $table->foreignId('dispatch_id')->nullable()->constrained('inventory_dispatches')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_dispatches');
        Schema::dropIfExists('inventory_productions');
        Schema::dropIfExists('inventory_bom_lines');
        Schema::dropIfExists('inventory_items');
    }
};
