<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Space 01's three queue-backed areas that had no data model at all -
// My Work, Approval Center, Alerts & Exceptions. Each gets its own
// immutable *_events ledger alongside the mutable record, matching this
// app's existing audit-ledger pattern (AuditLogEntry, AccessAuditLog) -
// the record holds current state, the events table holds the append-only
// history of how it got there.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('module');
            $table->nullableMorphs('linked');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('open');
            $table->string('priority')->default('medium');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status']);
        });

        Schema::create('workspace_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('workspace_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('workspace_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('module');
            $table->nullableMorphs('linked');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('risk_level')->default('low');
            $table->text('decision_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['approver_id', 'status']);
        });

        Schema::create('workspace_approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('workspace_approvals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('workspace_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category');
            $table->string('severity')->default('warning');
            $table->string('module');
            $table->nullableMorphs('linked');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status']);
        });

        Schema::create('workspace_exception_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_id')->constrained('workspace_exceptions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_exception_events');
        Schema::dropIfExists('workspace_exceptions');
        Schema::dropIfExists('workspace_approval_events');
        Schema::dropIfExists('workspace_approvals');
        Schema::dropIfExists('workspace_task_events');
        Schema::dropIfExists('workspace_tasks');
    }
};
