<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_verticals', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vertical_id')->constrained('access_verticals')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['vertical_id', 'code']);
        });

        Schema::create('access_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vertical_id')->constrained('access_verticals')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('access_units')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['vertical_id', 'code']);
        });

        Schema::create('access_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('access_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('label');
            $table->unsignedTinyInteger('level')->index();
            $table->foreignId('vertical_id')->nullable()->constrained('access_verticals')->nullOnDelete();
            $table->foreignId('parent_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
            $table->boolean('is_system')->default(false)->index();
            $table->string('status')->default('active')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('screen')->index();
            $table->string('action')->index();
            $table->string('label');
            $table->string('route_name')->nullable()->index();
            $table->string('widget_key')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('access_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('access_permissions')->cascadeOnDelete();
            $table->boolean('allowed')->default(false)->index();
            $table->boolean('delegable')->default(false);
            $table->string('scope_type')->default('self')->index();
            $table->json('scope_json')->nullable();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('access_user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('access_user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('access_permissions')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('allowed');
            $table->string('scope_type')->default('self');
            $table->json('scope_json')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('permission_key')->index();
            $table->string('provider_class');
            $table->unsignedTinyInteger('min_w')->default(2);
            $table->unsignedTinyInteger('max_w')->default(12);
            $table->unsignedTinyInteger('default_w')->default(4);
            $table->unsignedTinyInteger('min_h')->default(2);
            $table->unsignedTinyInteger('max_h')->default(8);
            $table->unsignedTinyInteger('default_h')->default(3);
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('access_role_dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->string('widget_key');
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->unsignedSmallInteger('w')->default(4);
            $table->unsignedSmallInteger('h')->default(3);
            $table->boolean('visible')->default(true);
            $table->boolean('locked')->default(false);
            $table->boolean('mandatory')->default(false);
            $table->json('config_json')->nullable();
            $table->timestamps();
            $table->unique(['role_id', 'widget_key']);
        });

        Schema::create('user_dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('widget_key');
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->unsignedSmallInteger('w')->default(4);
            $table->unsignedSmallInteger('h')->default(3);
            $table->boolean('visible')->default(true);
            $table->json('config_json')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'widget_key']);
        });

        Schema::create('access_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('target_type')->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'access_audit_logs',
            'user_dashboard_layouts',
            'access_role_dashboard_layouts',
            'dashboard_widgets',
            'access_user_permission_overrides',
            'access_user_roles',
            'access_role_permissions',
            'access_permissions',
            'access_roles',
            'access_team_members',
            'access_teams',
            'access_units',
            'access_verticals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
