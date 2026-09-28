<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class PaymentLinkResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly bool $sentByEmail = false,
        public readonly array $raw = [],
    ) {
    }
}
