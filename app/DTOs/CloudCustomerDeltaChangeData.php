<?php

namespace App\DTOs;

final readonly class CloudCustomerDeltaChangeData
{
    public function __construct(
        public string $memberId,
        public string $event,
        public string $modifiedAt
    ) {}
}
