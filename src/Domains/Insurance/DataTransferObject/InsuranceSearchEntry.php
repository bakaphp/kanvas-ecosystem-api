<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;

class InsuranceSearchEntry
{
    public function __construct(
        public readonly int $insurerId,
        public readonly string $insurerName,
        public readonly ?string $insurerLogo,
        public readonly string $provider,
        public readonly int $planId,
        public readonly string $planName,
        public readonly string $planSlug,
        public readonly InsuranceCoverageCategoryEnum $category,
        public readonly ?float $deductible,
        public readonly ?int $validityDays,
        public readonly bool $sponsored,
        public readonly int $recommendedScore,
        public readonly float $commission,
        public readonly QuoteResult $quote,
    ) {
    }

    public function price(): ?float
    {
        return $this->quote->total ?? $this->quote->premium;
    }

    public function canEmit(): ?bool
    {
        return $this->quote->canEmit;
    }

    public function isRecommended(): bool
    {
        return $this->recommendedScore > 0 && $this->canEmit() !== false;
    }
}
