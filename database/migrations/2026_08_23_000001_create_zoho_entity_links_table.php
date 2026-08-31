<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local Zoho record-ID map.
 *
 * Before this table, ZohoInventoryService had no memory of what it had already
 * pushed, so every write was preceded by a search-by-name/number against a
 * rate-limited API — and findPurchaseOrderByReference cost *two* calls, because the
 * list endpoint omits line_items and it had to follow up with a detail fetch. A
 * 5-line purchase order cost 9-14 API calls; with a stored id it costs one.
 *
 * payload_hash makes re-pushes free: if the canonical payload is unchanged since the
 * last success, the push short-circuits with zero HTTP calls. That is what makes the
 * backlog safe to re-scan.
 *
 * failure_count / quarantined_at break the checkpoint deadlock — see
 * ZohoInventoryService::pushChangedSince().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_entity_links', function (Blueprint $table) {
            $table->id();

            $table->string('syncable_type');
            $table->unsignedBigInteger('syncable_id');

            // 'contacts' | 'items' | 'purchaseorders' | 'bills' | 'purchasereceives'
            $table->string('zoho_module', 40);

            // Null until the record has been located in or created on Zoho.
            $table->string('zoho_id')->nullable();

            // sha256 of the last successfully pushed canonical payload.
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Consecutive *permanent* failures only. Transient failures (rate limits,
            // 5xx) must never count here — the record itself is fine.
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_failed_at')->nullable();

            // Set once failure_count passes the configured ceiling. Quarantined
            // records are skipped and the checkpoint advances past them, so one bad
            // row can no longer pin the entire backlog.
            $table->timestamp('quarantined_at')->nullable();

            $table->timestamps();

            $table->unique(['syncable_type', 'syncable_id', 'zoho_module'], 'zoho_links_unique');
            $table->index(['zoho_module', 'quarantined_at'], 'zoho_links_module_quarantine_idx');
            $table->index('zoho_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_entity_links');
    }
};
