<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class VerifyResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $transactionId,
        public readonly string $isoCode,
        public readonly string $responseCode,
        public readonly array $raw = [],
    ) {
    }
}
