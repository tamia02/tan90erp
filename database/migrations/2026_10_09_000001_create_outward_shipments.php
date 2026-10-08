<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Outward Shipment (replaces the simple Inventory "Dispatch"): purpose ->
// Tax Invoice or Delivery Challan -> e-Invoice IRN -> E-Way Bill, per the GST
// decision tree. The IRN / E-Way Bill APIs come later: for now the team creates
// them on the government portals and uploads the numbers + files here. Stock is
// deducted only when the shipment is marked Dispatched (via inventory_dispatches).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outward_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                      // OUT-00001
            $table->string('purpose', 20);                           // sales | transfer | job_work
            $table->foreignId('from_location_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->string('from_name');
            $table->string('from_gstin', 20)->nullable();
            $table->string('from_state', 60)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('tan90_customers')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('tan90_locations')->nullOnDelete();
            $table->string('to_name');
            $table->string('to_gstin', 20)->nullable();
            $table->string('to_state', 60)->nullable();
            $table->string('to_address')->nullable();
            $table->string('sales_order_number')->nullable();
            $table->decimal('total_value', 14, 2)->default(0);

            // Outcome of the GST decision tree (kept so the record shows why).
            $table->string('document_type', 20);                     // tax_invoice | delivery_challan
            $table->boolean('is_b2b')->default(false);
            $table->boolean('requires_irn')->default(false);
            $table->boolean('requires_ewb')->default(false);
            $table->json('decision_steps')->nullable();

            // Documents (entered/uploaded by the team; APIs later).
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_file')->nullable();
            $table->string('challan_number')->nullable()->unique();   // DC-00001, generated
            $table->string('irn', 80)->nullable();
            $table->string('irn_ack_number', 40)->nullable();
            $table->date('irn_ack_date')->nullable();
            $table->string('irn_file')->nullable();
            $table->string('ewb_number', 20)->nullable();
            $table->date('ewb_date')->nullable();
            $table->date('ewb_valid_until')->nullable();
            $table->string('ewb_file')->nullable();

            $table->string('vehicle_number')->nullable();
            $table->string('transporter')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('documents_pending'); // documents_pending | ready | dispatched | cancelled
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'purpose']);
        });

        Schema::create('outward_shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outward_shipment_id')->constrained('outward_shipments')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('rate', 14, 2)->default(0);
            $table->decimal('value', 14, 2)->default(0);
            $table->foreignId('dispatch_id')->nullable()->constrained('inventory_dispatches')->nullOnDelete(); // stock movement once dispatched
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outward_shipment_lines');
        Schema::dropIfExists('outward_shipments');
    }
};
