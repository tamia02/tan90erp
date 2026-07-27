<?php

namespace App\Access;

final readonly class RequestedGrant
{
    public function __construct(
        public string $permissionKey,
        public string $effect = 'allow',
        public string $scopeType = 'self',
        public ?string $fieldMode = null,
    ) {}
}
