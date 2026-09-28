<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsuranceStatusEnum;

class PolicyResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $policyNumber,
        public readonly InsuranceStatusEnum $status,
        public readonly array $raw = [],
    ) {
    }
}
