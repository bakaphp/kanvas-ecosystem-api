<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

/**
 * One policy line as the insurer names it. `code` is the identity — it travels back
 * to them on every quote; name and description are ours to author.
 */
class InsuranceProduct
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $requiresInspection = false,
        /** Insurers license subsets; a line we can't issue must not reach a customer. */
        public readonly bool $isAvailable = true,
        public readonly array $metadata = [],
    ) {
    }
}
