<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_shipment_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number');
        });
        Schema::create('logistics_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('logistics_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->uuid('creation_key')->unique();
            $table->foreignId('site_id')->nullable()->constrained('tan90_locations')->restrictOnDelete();
            $table->foreignId('zoho_sales_document_id')->nullable()->unique()->constrained('zoho_sales_documents')->restrictOnDelete();
            $table->foreignId('outward_shipment_id')->nullable()->constrained('outward_shipments')->restrictOnDelete();
            $table->string('channel', 12);
            $table->string('source_kind', 12);
            $table->string('reference_number');
            $table->string('provider_name');
            $table->string('source_location');
            $table->string('vertical')->nullable();
            $table->string('purpose');
            $table->text('destination');
            $table->json('items');
            $table->decimal('weight_kg', 14, 3);
            $table->string('priority', 20)->default('normal');
            $table->string('package_reference', 500)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->decimal('revenue', 14, 2)->nullable();
            $table->string('status', 20)->default('rtd');
            $table->string('transport_mode', 30)->nullable();
            $table->foreignId('vehicle_type_id')->nullable()->constrained('logistics_vehicle_types')->restrictOnDelete();
            $table->foreignId('transporter_id')->nullable()->constrained('tan90_transporters')->restrictOnDelete();
            $table->string('vehicle_number', 40)->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 30)->nullable();
            $table->decimal('base_freight', 14, 2)->default(0);
            $table->text('selection_reason')->nullable();
            $table->dateTime('rtd_at');
            $table->dateTime('expected_delivery_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('google_maps_url', 1000)->nullable();
            $table->string('poc_name')->nullable();
            $table->string('poc_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'status', 'id']);
            $table->index(['status', 'id']);
            $table->index('reference_number');
        });
        Schema::create('logistics_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('logistics_shipments')->cascadeOnDelete();
            $table->foreignId('transporter_id')->constrained('tan90_transporters')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedInteger('vendor_rank');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shipment_id', 'transporter_id']);
        });
        Schema::create('logistics_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('logistics_shipments')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 14, 2);
            $table->text('remarks');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('logistics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('logistics_shipments')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('logistics_edd_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('logistics_shipments')->cascadeOnDelete();
            $table->dateTime('old_edd');
            $table->dateTime('new_edd');
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('logistics_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('logistics_shipments')->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('path');
            $table->string('original_name');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('zoho_sales_documents', fn (Blueprint $table) => $table->index(['organization_id', 'type', 'document_date', 'id'], 'zoho_sales_document_date_list'));
    }

    public function down(): void
    {
        Schema::table('zoho_sales_documents', fn (Blueprint $table) => $table->dropIndex('zoho_sales_document_date_list'));
        foreach (['logistics_attachments', 'logistics_edd_revisions', 'logistics_events', 'logistics_charges', 'logistics_quotes', 'logistics_shipments', 'logistics_vehicle_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('flow_shipment_sequences');
    }
};
