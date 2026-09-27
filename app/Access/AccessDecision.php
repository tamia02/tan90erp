<?php

namespace App\Access;

final readonly class AccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $source,
        public ?string $scopeType = null,
        public ?string $fieldMode = null,
        public bool $locked = false,
        public string $reason = 'ok',
    ) {}
}
