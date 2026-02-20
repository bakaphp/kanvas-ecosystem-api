<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class CaptureResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $transactionId,
        public readonly array $raw = [],
    ) {
    }
}
