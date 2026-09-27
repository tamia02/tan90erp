<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per (local record, Zoho module) pair, remembering the Zoho id, the hash of
 * what was last pushed, and the record's failure history.
 *
 * @property string|null $zoho_id
 * @property string|null $payload_hash
 * @property int $failure_count
 * @property \Illuminate\Support\Carbon|null $quarantined_at
 */
#[Fillable([
    'syncable_type', 'syncable_id', 'zoho_module', 'zoho_id', 'payload_hash',
    'last_synced_at', 'failure_count', 'last_error', 'last_failed_at', 'quarantined_at',
])]
class ZohoEntityLink extends Model
{
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Fetch (or build, unsaved) the link row for a given local record and Zoho module. */
    public static function for(Model $model, string $module): self
    {
        return static::firstOrNew([
            'syncable_type' => $model->getMorphClass(),
            'syncable_id' => $model->getKey(),
            'zoho_module' => $module,
        ]);
    }

    public function isQuarantined(): bool
    {
        return $this->quarantined_at !== null;
    }

    /**
     * True when this exact payload was already accepted by Zoho, so pushing again
     * would be a no-op. This is the check that makes re-scanning the backlog free.
     */
    public function matches(string $payloadHash): bool
    {
        return $this->zoho_id !== null
            && $this->last_synced_at !== null
            && $this->payload_hash === $payloadHash;
    }

    public function markSynced(string $zohoId, string $payloadHash): void
    {
        $this->forceFill([
            'zoho_id' => $zohoId,
            'payload_hash' => $payloadHash,
            'last_synced_at' => now(),
            'failure_count' => 0,
            'last_error' => null,
            'last_failed_at' => null,
            'quarantined_at' => null,
        ])->save();
    }

    /**
     * Record a permanent (content) failure and quarantine once the record has burned
     * through its allowance. Transient failures must not come through here.
     */
    public function markPermanentFailure(string $error, int $maxFailures): void
    {
        $count = $this->failure_count + 1;

        $this->forceFill([
            'failure_count' => $count,
            'last_error' => mb_substr($error, 0, 2000),
            'last_failed_at' => now(),
            'quarantined_at' => $count >= $maxFailures ? now() : $this->quarantined_at,
        ])->save();
    }

    /** Note a transient failure without counting it against the record's budget. */
    public function touchTransientFailure(string $error): void
    {
        $this->forceFill([
            'last_error' => mb_substr($error, 0, 2000),
            'last_failed_at' => now(),
        ])->save();
    }

    /**
     * Canonical JSON hash — keys sorted recursively so that key ordering can never
     * produce a spurious mismatch and trigger a needless API call.
     */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(
            static::canonicalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(static fn ($item) => static::canonicalise($item), $value);

        // Only sort associative arrays; reordering a list would change its meaning.
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
