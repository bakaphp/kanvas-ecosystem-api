<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class TokenizeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $token,
        public readonly string $lastFour,
        public readonly string $brand,
        public readonly array $raw = [],
    ) {
    }
}
