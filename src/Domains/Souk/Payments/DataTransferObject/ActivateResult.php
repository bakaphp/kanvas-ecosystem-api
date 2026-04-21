<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

class ActivateResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $raw = [],
    ) {
    }
}
