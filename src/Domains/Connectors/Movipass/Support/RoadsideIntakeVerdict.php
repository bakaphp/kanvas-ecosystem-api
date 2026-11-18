<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeOutcomeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;

final class RoadsideIntakeVerdict
{
    /**
     * @param array<int, string> $reasons
     */
    public function __construct(
        public readonly RoadsideIntakeOutcomeEnum $outcome = RoadsideIntakeOutcomeEnum::PROCEED,
        public readonly array $reasons = [],
        public readonly ?RoadsideServiceTypeEnum $recommendedService = null,
    ) {
    }

    public function with(
        RoadsideIntakeOutcomeEnum $outcome,
        string $reason,
        ?RoadsideServiceTypeEnum $recommended
    ): self {
        return new self(
            outcome: $outcome->weight() > $this->outcome->weight() ? $outcome : $this->outcome,
            reasons: [...$this->reasons, $reason],
            recommendedService: $this->recommendedService ?? $recommended,
        );
    }

    public function proceeds(): bool
    {
        return $this->outcome !== RoadsideIntakeOutcomeEnum::NOT_PROCEED;
    }

    public function needsCabinReview(): bool
    {
        return $this->outcome === RoadsideIntakeOutcomeEnum::CABIN_REVIEW;
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'reasons' => $this->reasons,
            'recommended_service' => $this->recommendedService?->value,
        ];
    }
}
