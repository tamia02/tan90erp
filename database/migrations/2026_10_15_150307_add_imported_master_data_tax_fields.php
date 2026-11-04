<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sku_masters', function (Blueprint $table): void {
            $table->string('intra_state_tax_name')->nullable();
            $table->decimal('intra_state_tax_rate', 7, 3)->nullable();
            $table->string('intra_state_tax_type', 40)->nullable();
            $table->string('inter_state_tax_name')->nullable();
            $table->decimal('inter_state_tax_rate', 7, 3)->nullable();
            $table->string('inter_state_tax_type', 40)->nullable();
            $table->string('taxability_type', 80)->nullable();
            $table->string('tax_exemption_reason')->nullable();
        });

        Schema::table('vendor_masters', function (Blueprint $table): void {
            $table->string('gst_treatment', 100)->nullable();
            $table->string('source_of_supply', 100)->nullable();
            $table->string('msme_number', 100)->nullable();
            $table->string('msme_type', 60)->nullable();
            $table->string('tds_name', 100)->nullable();
            $table->decimal('tds_percentage', 7, 3)->nullable();
            $table->string('tds_section_code', 100)->nullable();
            $table->string('tds_section', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_masters', function (Blueprint $table): void {
            $table->dropColumn([
                'gst_treatment', 'source_of_supply', 'msme_number', 'msme_type',
                'tds_name', 'tds_percentage', 'tds_section_code', 'tds_section',
            ]);
        });

        Schema::table('sku_masters', function (Blueprint $table): void {
            $table->dropColumn([
                'intra_state_tax_name', 'intra_state_tax_rate', 'intra_state_tax_type',
                'inter_state_tax_name', 'inter_state_tax_rate', 'inter_state_tax_type',
                'taxability_type', 'tax_exemption_reason',
            ]);
        });
    }
};
