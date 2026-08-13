<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

class DocumentUploadResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly int $uploaded = 0,
        public readonly array $raw = [],
    ) {
    }
}
