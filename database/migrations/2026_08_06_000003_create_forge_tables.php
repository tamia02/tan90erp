<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Space 05 (Forge) — Plan, Make & Quality. Forge reads released product
// definitions from Blueprint (tan90_finished_goods/boms/recipes/routings/
// work_centers) rather than owning its own copies, and never touches a
// stock balance directly - material events post through Origin's ledger.
// See resources/views/forge or ARCHITECTURE.md in the space05 source zip
// for the full contract.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_production_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_number')->unique();
            $table->foreignId('finished_good_id')->constrained('tan90_finished_goods');
            $table->string('plant')->nullable();
            $table->decimal('target_qty', 14, 3);
            $table->string('uom', 20);
            $table->date('due_date');
            $table->string('status', 20)->default('draft'); // draft, approved, frozen, cancelled
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_number')->unique();
            $table->foreignId('production_plan_id')->nullable()->constrained('forge_production_plans')->nullOnDelete();
            $table->foreignId('finished_good_id')->constrained('tan90_finished_goods');
            $table->foreignId('bom_id')->nullable()->constrained('tan90_boms')->nullOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained('tan90_recipes')->nullOnDelete();
            $table->foreignId('routing_id')->nullable()->constrained('tan90_routings')->nullOnDelete();
            $table->string('plant')->nullable();
            $table->string('batch_number')->nullable();
            $table->decimal('target_qty', 14, 3);
            $table->decimal('good_qty', 14, 3)->default(0);
            $table->decimal('rework_qty', 14, 3)->default(0);
            $table->decimal('rejected_qty', 14, 3)->default(0);
            $table->string('uom', 20);
            // draft, released, material_reserved, material_issued, in_progress,
            // reconciliation, final_qc_pending, released_to_fg, rework, rejected, closed
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->nullable()->constrained('tan90_work_centers')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('plant')->nullable();
            $table->string('state', 20)->default('idle'); // idle, setup, running, down, maintenance
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('forge_machine_downtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('forge_machines')->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('forge_work_orders')->nullOnDelete();
            $table->string('category', 50); // breakdown, planned_stop, changeover, material_shortage, quality_hold
            $table->string('severity', 20)->default('minor');
            $table->text('observation')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_job_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->foreignId('routing_operation_id')->nullable()->constrained('tan90_routing_operations')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('operation_name');
            $table->foreignId('machine_id')->nullable()->constrained('forge_machines')->nullOnDelete();
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('planned_qty', 14, 3)->nullable();
            $table->string('status', 20)->default('pending'); // pending, started, paused, completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('process_parameters_json')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->string('item_code');
            $table->string('item_name');
            $table->string('lot_number')->nullable();
            $table->decimal('qty', 14, 3);
            $table->string('uom', 20);
            $table->string('movement_type', 20); // issue, return, consumption
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('forge_production_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained('forge_job_cards')->nullOnDelete();
            $table->decimal('good_qty', 14, 3)->default(0);
            $table->decimal('rework_qty', 14, 3)->default(0);
            $table->decimal('rejected_qty', 14, 3)->default(0);
            $table->string('uom', 20);
            $table->string('status', 20)->default('draft'); // draft, approved
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_wastage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('qty', 14, 3);
            $table->string('uom', 20);
            $table->string('reason', 100);
            $table->string('disposition', 20)->default('pending'); // pending, rework, recover, return, destruction, approved_scrap
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_quality_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained('forge_job_cards')->nullOnDelete();
            $table->string('checkpoint');
            $table->string('result', 20)->nullable(); // pass, fail
            $table->text('specification_snapshot')->nullable();
            $table->text('evidence')->nullable();
            $table->string('status', 20)->default('open'); // open, released
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_final_qc_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->decimal('accepted_qty', 14, 3)->default(0);
            $table->decimal('rejected_qty', 14, 3)->default(0);
            $table->decimal('rework_qty', 14, 3)->default(0);
            $table->text('specification_results')->nullable();
            $table->string('result', 20); // released, rework, rejected
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_deviations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->nullable()->constrained('forge_work_orders')->nullOnDelete();
            $table->string('source_type', 30); // process, quality, machine, traceability
            $table->text('description');
            $table->text('containment')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('disposition', 20)->nullable(); // use_as_is, rework, reject, return
            $table->text('capa_action')->nullable();
            $table->foreignId('capa_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('capa_target_date')->nullable();
            $table->text('effectiveness_check')->nullable();
            $table->string('status', 20)->default('open'); // open, investigating, disposed, capa_open, closed
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forge_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders')->cascadeOnDelete();
            $table->string('batch_number')->unique();
            $table->decimal('qty', 14, 3);
            $table->string('uom', 20);
            $table->string('status', 20)->default('in_process'); // in_process, released, rejected
            $table->date('shelf_life_date')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_batches');
        Schema::dropIfExists('forge_deviations');
        Schema::dropIfExists('forge_final_qc_results');
        Schema::dropIfExists('forge_quality_holds');
        Schema::dropIfExists('forge_wastage_records');
        Schema::dropIfExists('forge_production_entries');
        Schema::dropIfExists('forge_material_issues');
        Schema::dropIfExists('forge_job_cards');
        Schema::dropIfExists('forge_machine_downtime_events');
        Schema::dropIfExists('forge_machines');
        Schema::dropIfExists('forge_work_orders');
        Schema::dropIfExists('forge_production_plans');
    }
};
