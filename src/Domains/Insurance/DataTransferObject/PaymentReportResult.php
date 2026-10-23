<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class PaymentReportResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $raw = [],
    ) {
    }
}
