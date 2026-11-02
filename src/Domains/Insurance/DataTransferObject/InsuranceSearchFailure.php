<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsuranceSearchFailureReasonEnum;

class InsuranceSearchFailure
{
    public function __construct(
        public readonly int $insurerId,
        public readonly string $insurerName,
        public readonly InsuranceSearchFailureReasonEnum $reason,
        public readonly string $message,
    ) {
    }
}
