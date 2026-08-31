<?php

namespace App\Services\Zoho;

/**
 * Result of a single gated Zoho call. Replaces the scattered
 * `$response->successful() && (int) $response->json('code') === 0` checks so that
 * every call site interprets Zoho's two-level status (HTTP status *and* the `code`
 * field in the body) the same way — and so a call that never left the process
 * (blocked by the circuit breaker or the budget) is representable at all.
 */
final class ZohoResult
{
    public function __construct(
        public readonly ZohoOutcome $outcome,
        public readonly ?int $status,
        public readonly array $body,
        public readonly string $message,
        /** True when the call was stopped locally and no HTTP request was made. */
        public readonly bool $blocked = false,
    ) {}

    public static function blocked(string $reason): self
    {
        return new self(ZohoOutcome::Transient, null, [], $reason, true);
    }

    public function ok(): bool
    {
        return $this->outcome === ZohoOutcome::Success;
    }

    public function isTransient(): bool
    {
        return $this->outcome === ZohoOutcome::Transient;
    }

    public function isPermanent(): bool
    {
        return $this->outcome === ZohoOutcome::Permanent;
    }

    /** Dot-notation read into the decoded response body. */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return data_get($this->body, $key, $default);
    }
}
