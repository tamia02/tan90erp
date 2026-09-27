<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'access_mode')) {
                $table->string('access_mode')->default('legacy')->index()->after('role');
            }
        });

        Schema::table('access_units', function (Blueprint $table) {
            if (! Schema::hasColumn('access_units', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            foreach (['tan90_business_unit_id', 'tan90_plant_id', 'tan90_location_id', 'tan90_warehouse_id'] as $column) {
                if (! Schema::hasColumn('access_units', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->index();
                }
            }
        });

        Schema::table('access_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('access_roles', 'hierarchy_level')) {
                $table->unsignedTinyInteger('hierarchy_level')->nullable()->index()->after('description');
            }
            if (! Schema::hasColumn('access_roles', 'role_kind')) {
                $table->string('role_kind')->default('system')->index()->after('hierarchy_level');
            }
            if (! Schema::hasColumn('access_roles', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('status');
            }
        });

        Schema::table('access_permissions', function (Blueprint $table) {
            foreach ([
                'category' => fn () => $table->string('category')->default('action')->index()->after('screen'),
                'description' => fn () => $table->text('description')->nullable()->after('label'),
                'ui_key' => fn () => $table->string('ui_key')->nullable()->index()->after('route_name'),
                'field_key' => fn () => $table->string('field_key')->nullable()->index()->after('ui_key'),
                'allowed_scope_types' => fn () => $table->json('allowed_scope_types')->nullable()->after('widget_key'),
                'is_sensitive' => fn () => $table->boolean('is_sensitive')->default(false)->index()->after('sort_order'),
                'status' => fn () => $table->string('status')->default('active')->index()->after('is_sensitive'),
            ] as $column => $definition) {
                if (! Schema::hasColumn('access_permissions', $column)) {
                    $definition();
                }
            }
        });

        Schema::table('access_role_permissions', function (Blueprint $table) {
            foreach ([
                'effect' => fn () => $table->string('effect')->default('inherit')->index()->after('permission_id'),
                'max_scope_type' => fn () => $table->string('max_scope_type')->nullable()->index()->after('scope_type'),
                'scope_constraints_json' => fn () => $table->json('scope_constraints_json')->nullable()->after('max_scope_type'),
                'field_mode' => fn () => $table->string('field_mode')->nullable()->index()->after('scope_constraints_json'),
                'locked' => fn () => $table->boolean('locked')->default(false)->index()->after('field_mode'),
                'granted_by' => fn () => $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete()->after('locked'),
            ] as $column => $definition) {
                if (! Schema::hasColumn('access_role_permissions', $column)) {
                    $definition();
                }
            }
        });

        Schema::table('access_user_permission_overrides', function (Blueprint $table) {
            foreach ([
                'effect' => fn () => $table->string('effect')->default('allow')->index()->after('permission_id'),
                'scope_constraints_json' => fn () => $table->json('scope_constraints_json')->nullable()->after('scope_json'),
                'field_mode' => fn () => $table->string('field_mode')->nullable()->index()->after('scope_constraints_json'),
                'reason' => fn () => $table->text('reason')->nullable()->after('expires_at'),
                'revoked_at' => fn () => $table->timestamp('revoked_at')->nullable()->index()->after('reason'),
                'revoked_by' => fn () => $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete()->after('revoked_at'),
            ] as $column => $definition) {
                if (! Schema::hasColumn('access_user_permission_overrides', $column)) {
                    $definition();
                }
            }
        });

        Schema::create('access_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('hierarchy_level')->index();
            $table->foreignId('vertical_id')->nullable()->constrained('access_verticals')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('access_units')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('access_teams')->nullOnDelete();
            $table->foreignId('reports_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_primary')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status', 'is_primary']);
        });

        Schema::create('access_user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignId('vertical_id')->nullable()->constrained('access_verticals')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('access_units')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('access_teams')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'vertical_id', 'unit_id', 'team_id'], 'aura_unique_scope');
        });

        Schema::create('access_saved_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('screen_key')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('owner_level')->index();
            $table->string('base_view_key')->nullable();
            $table->json('columns_json')->nullable();
            $table->json('filters_json')->nullable();
            $table->json('sort_json')->nullable();
            $table->json('group_json')->nullable();
            $table->json('display_json')->nullable();
            $table->json('row_actions_json')->nullable();
            $table->json('locked_parts_json')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('access_saved_view_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_view_id')->constrained('access_saved_views')->cascadeOnDelete();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->boolean('is_default')->default(false);
            $table->boolean('can_personalise')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assignable_type', 'assignable_id'], 'asva_assignable_idx');
        });

        Schema::create('dashboard_widget_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('permission_key')->index();
            $table->string('provider_class');
            $table->json('supported_variants_json')->nullable();
            $table->json('settings_schema_json')->nullable();
            $table->unsignedTinyInteger('min_w')->default(2);
            $table->unsignedTinyInteger('min_h')->default(2);
            $table->unsignedTinyInteger('max_w')->default(12);
            $table->unsignedTinyInteger('max_h')->default(8);
            $table->unsignedTinyInteger('default_w')->default(4);
            $table->unsignedTinyInteger('default_h')->default(3);
            $table->boolean('supports_refresh')->default(true);
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('dashboard_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('owner_type')->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->foreignId('parent_template_id')->nullable()->constrained('dashboard_templates')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('responsive_layouts_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('dashboard_templates')->cascadeOnDelete();
            $table->string('widget_key');
            $table->string('page_key')->default('main');
            $table->string('tab_key')->nullable();
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->unsignedSmallInteger('w')->default(4);
            $table->unsignedSmallInteger('h')->default(3);
            $table->unsignedSmallInteger('mobile_x')->nullable();
            $table->unsignedSmallInteger('mobile_y')->nullable();
            $table->unsignedSmallInteger('mobile_w')->nullable();
            $table->unsignedSmallInteger('mobile_h')->nullable();
            $table->boolean('visible')->default(true);
            $table->boolean('mandatory')->default(false);
            $table->boolean('position_locked')->default(false);
            $table->boolean('size_locked')->default(false);
            $table->boolean('config_locked')->default(false);
            $table->json('config_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['template_id', 'widget_key', 'page_key']);
        });

        Schema::create('dashboard_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('dashboard_templates')->cascadeOnDelete();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assignable_type', 'assignable_id', 'priority'], 'dash_assignable_priority_idx');
        });

        Schema::create('dashboard_user_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('base_template_id')->nullable()->constrained('dashboard_templates')->nullOnDelete();
            $table->json('overrides_json')->nullable();
            $table->json('draft_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('access_audit_logs', function (Blueprint $table) {
            foreach ([
                'actor_user_id' => fn () => $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete()->after('id'),
                'hierarchy_context_json' => fn () => $table->json('hierarchy_context_json')->nullable()->after('target_id'),
                'reason' => fn () => $table->text('reason')->nullable()->after('after_json'),
                'request_id' => fn () => $table->string('request_id')->nullable()->index()->after('reason'),
            ] as $column => $definition) {
                if (! Schema::hasColumn('access_audit_logs', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        foreach ([
            'dashboard_user_overrides',
            'dashboard_assignments',
            'dashboard_template_items',
            'dashboard_templates',
            'dashboard_widget_catalog',
            'access_saved_view_assignments',
            'access_saved_views',
            'access_user_role_assignments',
            'access_positions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
