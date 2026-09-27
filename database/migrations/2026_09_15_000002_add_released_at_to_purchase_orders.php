<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client's requested "PO Release" workflow: a PO created locally in PO
 * Master (a purchasing person assigning a brand-new PO to a vendor,
 * distinct from a vendor's own document submission) should start as a
 * draft, invisible to the vendor, until someone explicitly releases it.
 * POs synced in from Zoho CRM are treated as already-released, since
 * they represent a real PO the vendor already knows about through
 * Zoho/other channels, not something created fresh in this app.
 *
 * Backfilling every existing row to released_at = created_at is required
 * here, not optional: without it, every PO that predates this migration
 * would instantly vanish from every vendor's portal the moment this
 * deploys, since the new gate defaults to "not released" on a bare
 * nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('status');
        });

        DB::table('purchase_orders')->whereNull('released_at')->update([
            'released_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
