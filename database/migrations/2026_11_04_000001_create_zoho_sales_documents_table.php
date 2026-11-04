<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_sales_documents', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id');
            $table->string('type', 20);
            $table->string('zoho_id');
            $table->string('number')->nullable();
            $table->string('salesorder_id')->nullable()->index();
            $table->string('salesorder_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('status')->nullable();
            $table->date('document_date')->nullable();
            $table->decimal('total', 20, 4)->nullable();
            $table->json('payload');
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['organization_id', 'type', 'zoho_id'], 'zoho_sales_document_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_sales_documents');
    }
};
