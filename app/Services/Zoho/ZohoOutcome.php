<?php

namespace App\Services\Zoho;

/**
 * How a Zoho response should be treated by the retry/checkpoint machinery.
 *
 * This distinction is the whole reason the previous implementation deadlocked:
 * it treated "Zoho is throttling us" and "Zoho will never accept this record"
 * identically, so a single permanently-invalid row pinned the sync checkpoint
 * forever and every run re-pushed the entire backlog.
 */
enum ZohoOutcome: string
{
    /** Zoho accepted it. Persist the id + payload hash. */
    case Success = 'success';

    /**
     * Retryable — rate limits, 5xx, timeouts, or a locally-blocked call. Must NOT
     * advance the checkpoint and must NOT count against a record's failure budget,
     * because the record itself is fine.
     */
    case Transient = 'transient';

    /**
     * Zoho rejected the record's content and will keep rejecting it — validation
     * errors, invalid GST, missing required fields. Counts against the record's
     * failure budget and eventually quarantines it so the backlog can drain.
     */
    case Permanent = 'permanent';

    public function isRetryable(): bool
    {
        return $this === self::Transient;
    }
}
