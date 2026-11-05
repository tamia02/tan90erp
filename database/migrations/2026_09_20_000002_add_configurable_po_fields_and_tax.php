<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_order_lines', 'gst_rate')) {
            Schema::table('purchase_order_lines', function (Blueprint $table) {
                $table->decimal('gst_rate', 5, 2)->nullable()->after('tax');
            });
        }

        if (! Schema::hasTable('purchase_order_custom_field_definitions')) {
            Schema::create('purchase_order_custom_field_definitions', function (Blueprint $table) {
                $table->id();
                $table->enum('scope', ['header', 'item']);
                $table->string('field_key')->unique();
                $table->string('label');
                $table->enum('input_type', ['text', 'number', 'date', 'textarea'])->default('text');
                $table->string('material_type')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_order_terms_templates')) {
            Schema::create('purchase_order_terms_templates', function (Blueprint $table) {
                $table->id();
                $table->string('material_type');
                $table->string('name');
                $table->longText('body');
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['material_type', 'name']);
            });
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('purchase_orders', 'material_type')) {
                $table->string('material_type')->nullable()->after('purchase_nature');
            }
            if (! Schema::hasColumn('purchase_orders', 'terms_template_id')) {
                $table->foreignId('terms_template_id')->nullable()->constrained('purchase_order_terms_templates')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'terms_template_id')) {
            Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('terms_template_id'));
        }
        foreach (['purchase_orders' => ['material_type', 'submitted_at'], 'purchase_order_lines' => ['gst_rate']] as $tableName => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
        Schema::dropIfExists('purchase_order_terms_templates');
        Schema::dropIfExists('purchase_order_custom_field_definitions');
    }
};
